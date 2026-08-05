package expirer

import (
	"context"
	"log/slog"
)

type APIClient interface {
	Expire(ctx context.Context) (int, error)
}

type APIExpirer struct {
	client APIClient
	logger *slog.Logger
}

func NewAPIExpirer(client APIClient, logger *slog.Logger) *APIExpirer {
	return &APIExpirer{client: client, logger: logger}
}

func (e *APIExpirer) Expire(ctx context.Context) (Result, error) {
	total, err := e.client.Expire(ctx)
	if err != nil {
		e.logger.Error("api expiration failed", "error", err.Error())
		return Result{}, err
	}
	e.logger.Info("api expiration completed", "updated", total)
	return Result{Total: total}, nil
}
