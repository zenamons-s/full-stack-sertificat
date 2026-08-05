package expirer

import (
	"context"
	"io"
	"log/slog"
	"testing"
	"time"

	"gift-certificates/worker/internal/clock"
	"gift-certificates/worker/internal/storage"
)

func TestExpiredAtUsesInjectedTime(t *testing.T) {
	now := time.Date(2026, 8, 5, 0, 0, 0, 0, time.UTC)
	cases := []struct {
		name      string
		expiresAt time.Time
		want      bool
	}{
		{name: "past", expiresAt: now.Add(-time.Second), want: true},
		{name: "equal", expiresAt: now, want: true},
		{name: "future", expiresAt: now.Add(time.Second), want: false},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if got := isExpired(tc.expiresAt, clock.FixedClock{Time: now}); got != tc.want {
				t.Fatalf("isExpired() = %v, want %v", got, tc.want)
			}
		})
	}
}

func TestEmptySelectionDoesNotUpdate(t *testing.T) {
	store := &fakeStore{batches: [][]storage.ExpiredCandidate{{}}}
	cache := &fakeCache{}
	expirer := NewDBExpirer(store, cache, clock.FixedClock{Time: time.Now()}, 500, 1, discardLogger())

	result, err := expirer.Expire(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if result.Total != 0 {
		t.Fatalf("total = %d, want 0", result.Total)
	}
	if store.expireCalls != 1 {
		t.Fatalf("expire calls = %d, want 1", store.expireCalls)
	}
	if store.updateCalls != 0 {
		t.Fatalf("update calls = %d, want 0", store.updateCalls)
	}
	if cache.invalidations != 0 {
		t.Fatalf("cache invalidations = %d, want 0", cache.invalidations)
	}
}

func TestBatchingWhenMoreThanBatchSize(t *testing.T) {
	store := &fakeStore{batches: [][]storage.ExpiredCandidate{
		{{ID: 1}, {ID: 2}},
		{{ID: 3}},
		{},
	}}
	cache := &fakeCache{}
	expirer := NewDBExpirer(store, cache, clock.FixedClock{Time: time.Now()}, 2, 1, discardLogger())

	result, err := expirer.Expire(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if result.Total != 3 {
		t.Fatalf("total = %d, want 3", result.Total)
	}
	if store.expireCalls != 3 {
		t.Fatalf("expire calls = %d, want 3", store.expireCalls)
	}
	if store.updateCalls != 2 {
		t.Fatalf("update calls = %d, want 2", store.updateCalls)
	}
	if cache.invalidations != 1 {
		t.Fatalf("cache invalidations = %d, want 1", cache.invalidations)
	}
}

func TestAuditDiff(t *testing.T) {
	diff := storage.AuditDiff("active", "expired")
	if diff["status"]["old"] != "active" || diff["status"]["new"] != "expired" {
		t.Fatalf("unexpected diff: %#v", diff)
	}
}

type fakeStore struct {
	batches     [][]storage.ExpiredCandidate
	expireCalls int
	updateCalls int
}

func (s *fakeStore) TryAdvisoryLock(context.Context, int64) (bool, error) {
	return true, nil
}

func (s *fakeStore) AdvisoryUnlock(context.Context, int64) error {
	return nil
}

func (s *fakeStore) ExpireBatch(_ context.Context, _ int, _ time.Time) ([]storage.ExpiredCandidate, error) {
	s.expireCalls++
	if len(s.batches) == 0 {
		return nil, nil
	}
	batch := s.batches[0]
	s.batches = s.batches[1:]
	if len(batch) > 0 {
		s.updateCalls++
	}
	return batch, nil
}

func (s *fakeStore) Ping(context.Context) error {
	return nil
}

func (s *fakeStore) Close() {}

type fakeCache struct {
	invalidations int
}

func (c *fakeCache) Invalidate(context.Context) error {
	c.invalidations++
	return nil
}

func (c *fakeCache) Close() error {
	return nil
}

func discardLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}
