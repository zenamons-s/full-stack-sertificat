package storage

import (
	"context"
	"encoding/json"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
)

type ExpiredCandidate struct {
	ID      int64
	Title   string
	Version int
}

type Store interface {
	TryAdvisoryLock(ctx context.Context, lockID int64) (bool, error)
	AdvisoryUnlock(ctx context.Context, lockID int64) error
	ExpireBatch(ctx context.Context, batchSize int, now time.Time) ([]ExpiredCandidate, error)
	Ping(ctx context.Context) error
	Close()
}

type Pool struct {
	pool *pgxpool.Pool
}

func NewPool(ctx context.Context, databaseURL string) (*Pool, error) {
	pool, err := pgxpool.New(ctx, databaseURL)
	if err != nil {
		return nil, err
	}
	if err := pool.Ping(ctx); err != nil {
		pool.Close()
		return nil, err
	}
	return &Pool{pool: pool}, nil
}

func (p *Pool) TryAdvisoryLock(ctx context.Context, lockID int64) (bool, error) {
	var locked bool
	err := p.pool.QueryRow(ctx, "SELECT pg_try_advisory_lock($1)", lockID).Scan(&locked)
	return locked, err
}

func (p *Pool) AdvisoryUnlock(ctx context.Context, lockID int64) error {
	_, err := p.pool.Exec(ctx, "SELECT pg_advisory_unlock($1)", lockID)
	return err
}

func (p *Pool) ExpireBatch(ctx context.Context, batchSize int, now time.Time) ([]ExpiredCandidate, error) {
	tx, err := p.pool.BeginTx(ctx, pgx.TxOptions{})
	if err != nil {
		return nil, err
	}
	defer func() {
		_ = tx.Rollback(ctx)
	}()

	rows, err := tx.Query(ctx, `
		SELECT id, title, version
		FROM certificates
		WHERE status='active' AND expires_at <= $1 AND deleted_at IS NULL
		ORDER BY expires_at
		LIMIT $2 FOR UPDATE SKIP LOCKED
	`, now, batchSize)
	if err != nil {
		return nil, err
	}
	candidates, err := pgx.CollectRows(rows, pgx.RowToStructByName[ExpiredCandidate])
	if err != nil {
		return nil, err
	}
	if len(candidates) == 0 {
		if err := tx.Commit(ctx); err != nil {
			return nil, err
		}
		return candidates, nil
	}

	ids := make([]int64, 0, len(candidates))
	for _, candidate := range candidates {
		ids = append(ids, candidate.ID)
	}

	if _, err := tx.Exec(ctx, `
		UPDATE certificates
		SET status='expired', version=version+1, updated_at=$1
		WHERE id = ANY($2)
	`, now, ids); err != nil {
		return nil, err
	}

	for _, candidate := range candidates {
		changes, err := json.Marshal(AuditDiff("active", "expired"))
		if err != nil {
			return nil, err
		}
		if _, err := tx.Exec(ctx, `
			INSERT INTO certificate_audit (certificate_id, actor_type, action, changes, created_at)
			VALUES ($1, 'worker', 'expired', $2, $3)
		`, candidate.ID, string(changes), now); err != nil {
			return nil, err
		}
	}

	if err := tx.Commit(ctx); err != nil {
		return nil, err
	}
	return candidates, nil
}

func (p *Pool) Ping(ctx context.Context) error {
	return p.pool.Ping(ctx)
}

func (p *Pool) Close() {
	p.pool.Close()
}

func AuditDiff(oldStatus, newStatus string) map[string]map[string]string {
	return map[string]map[string]string{
		"status": {
			"old": oldStatus,
			"new": newStatus,
		},
	}
}
