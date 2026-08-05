package cache

import (
	"context"
	"log/slog"

	"github.com/redis/go-redis/v9"
)

type Invalidator interface {
	Invalidate(ctx context.Context) error
	Close() error
}

type RedisInvalidator struct {
	client *redis.Client
	logger *slog.Logger
}

func NewRedis(redisURL string, logger *slog.Logger) *RedisInvalidator {
	options, err := redis.ParseURL(redisURL)
	if err != nil {
		logger.Warn("redis url parse failed", "error", err.Error())
		options = &redis.Options{Addr: "redis:6379"}
	}
	return &RedisInvalidator{client: redis.NewClient(options), logger: logger}
}

func (r *RedisInvalidator) Invalidate(ctx context.Context) error {
	err := r.client.Incr(ctx, "certificates:gen").Err()
	if err != nil {
		r.logger.Warn("cache generation increment failed", "error", err.Error())
		return err
	}
	r.logger.Info("cache generation incremented", "key", "certificates:gen")
	return nil
}

func (r *RedisInvalidator) Close() error {
	return r.client.Close()
}
