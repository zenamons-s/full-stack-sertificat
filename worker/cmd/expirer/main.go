package main

import (
	"context"
	"flag"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"gift-certificates/worker/internal/apiclient"
	"gift-certificates/worker/internal/cache"
	"gift-certificates/worker/internal/clock"
	"gift-certificates/worker/internal/config"
	"gift-certificates/worker/internal/expirer"
	"gift-certificates/worker/internal/health"
	"gift-certificates/worker/internal/storage"
)

func main() {
	healthcheck := flag.Bool("healthcheck", false, "check local health endpoint")
	flag.Parse()

	if *healthcheck {
		checkHealth()
		return
	}

	cfg, err := config.Load()
	if err != nil {
		slog.New(slog.NewJSONHandler(os.Stdout, nil)).Error("worker config failed", "error", err.Error())
		os.Exit(1)
	}

	logger := newLogger(cfg.LogLevel)
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	store, err := storage.NewPool(ctx, cfg.DatabaseURL)
	if err != nil {
		logger.Error("postgres connection failed", "error", err.Error())
		os.Exit(1)
	}
	defer store.Close()

	cacheClient := cache.NewRedis(cfg.RedisURL, logger)
	defer cacheClient.Close()

	healthServer := health.NewServer(":8081", store, logger)
	go func() {
		if err := healthServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			logger.Error("health server failed", "error", err.Error())
			stop()
		}
	}()

	var runner expirer.Expirer
	switch cfg.SyncMode {
	case "db":
		runner = expirer.NewDBExpirer(store, cacheClient, clock.SystemClock{}, cfg.WorkerBatchSize, cfg.WorkerAdvisoryLockID, logger)
	case "api":
		runner = expirer.NewAPIExpirer(apiclient.New(cfg.APIBaseURL, cfg.WorkerAPIEmail, cfg.WorkerAPIPassword, logger), logger)
	default:
		logger.Error("unsupported sync mode", "sync_mode", cfg.SyncMode)
		os.Exit(1)
	}

	logger.Info("worker started", "sync_mode", cfg.SyncMode, "interval", cfg.WorkerInterval.String(), "batch_size", cfg.WorkerBatchSize)
	runLoop(ctx, cfg.WorkerInterval, runner, logger)

	shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := healthServer.Shutdown(shutdownCtx); err != nil {
		logger.Error("health server shutdown failed", "error", err.Error())
	}
	logger.Info("worker stopped")
}

func runLoop(ctx context.Context, interval time.Duration, runner expirer.Expirer, logger *slog.Logger) {
	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	for {
		if _, err := runner.Expire(ctx); err != nil {
			logger.Error("expiration run failed", "error", err.Error())
		}

		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
	}
}

func checkHealth() {
	client := http.Client{Timeout: 2 * time.Second}
	resp, err := client.Get("http://127.0.0.1:8081/healthz")
	if err != nil {
		os.Exit(1)
	}
	defer resp.Body.Close()
	if resp.StatusCode < http.StatusOK || resp.StatusCode >= http.StatusMultipleChoices {
		os.Exit(1)
	}
}

func newLogger(level string) *slog.Logger {
	var slogLevel slog.Level
	switch level {
	case "debug":
		slogLevel = slog.LevelDebug
	case "warn", "warning":
		slogLevel = slog.LevelWarn
	case "error":
		slogLevel = slog.LevelError
	default:
		slogLevel = slog.LevelInfo
	}

	return slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: slogLevel}))
}
