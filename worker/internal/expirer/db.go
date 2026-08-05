package expirer

import (
	"context"
	"fmt"
	"log/slog"
	"math/rand"
	"time"

	"gift-certificates/worker/internal/cache"
	"gift-certificates/worker/internal/clock"
	"gift-certificates/worker/internal/storage"
)

type DBExpirer struct {
	store     storage.Store
	cache     cache.Invalidator
	clock     clock.Clock
	batchSize int
	lockID    int64
	logger    *slog.Logger
}

func NewDBExpirer(store storage.Store, cache cache.Invalidator, clock clock.Clock, batchSize int, lockID int64, logger *slog.Logger) *DBExpirer {
	return &DBExpirer{store: store, cache: cache, clock: clock, batchSize: batchSize, lockID: lockID, logger: logger}
}

func (e *DBExpirer) Expire(ctx context.Context) (Result, error) {
	start := time.Now()
	runID := newRunID(start)
	logger := e.logger.With("run_id", runID)

	locked, err := e.store.TryAdvisoryLock(ctx, e.lockID)
	if err != nil {
		logger.Error("expiration advisory lock failed", "error", err.Error())
		return Result{RunID: runID}, err
	}
	if !locked {
		logger.Warn("expiration skipped, advisory lock busy")
		return Result{RunID: runID, Skipped: true}, nil
	}
	defer func() {
		unlockCtx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
		defer cancel()
		if err := e.store.AdvisoryUnlock(unlockCtx, e.lockID); err != nil {
			logger.Error("expiration advisory unlock failed", "error", err.Error())
		}
	}()

	total := 0
	batch := 0
	now := e.clock.Now()
	for {
		if err := ctx.Err(); err != nil {
			return Result{RunID: runID, Total: total}, err
		}
		batch++
		candidates, err := e.store.ExpireBatch(ctx, e.batchSize, now)
		if err != nil {
			logger.Error("expiration batch failed", "batch", batch, "error", err.Error())
			return Result{RunID: runID, Total: total}, err
		}
		if len(candidates) == 0 {
			break
		}
		total += len(candidates)
		logger.Info("expiration batch completed", "batch", batch, "count", len(candidates), "ids_sample", sampleIDs(candidates, 5))
	}

	if total > 0 {
		_ = e.cache.Invalidate(ctx)
	}
	logger.Info("expiration run completed", "found", total, "updated", total, "duration_ms", time.Since(start).Milliseconds())
	return Result{RunID: runID, Total: total}, nil
}

func sampleIDs(candidates []storage.ExpiredCandidate, limit int) []int64 {
	if len(candidates) < limit {
		limit = len(candidates)
	}
	ids := make([]int64, 0, limit)
	for i := 0; i < limit; i++ {
		ids = append(ids, candidates[i].ID)
	}
	return ids
}

func newRunID(t time.Time) string {
	return fmt.Sprintf("%d-%06d", t.UTC().UnixMilli(), rand.Intn(1000000))
}
