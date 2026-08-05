package expirer

import "context"

type Result struct {
	RunID   string
	Total   int
	Skipped bool
}

type Expirer interface {
	Expire(ctx context.Context) (Result, error)
}
