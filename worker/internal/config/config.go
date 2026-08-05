package config

import (
	"errors"
	"fmt"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"
)

type Config struct {
	DatabaseURL          string
	RedisURL             string
	WorkerInterval       time.Duration
	WorkerBatchSize      int
	WorkerAdvisoryLockID int64
	SyncMode             string
	APIBaseURL           string
	WorkerAPIEmail       string
	WorkerAPIPassword    string
	LogLevel             string
}

func Load() (Config, error) {
	required := []string{
		"DATABASE_URL", "REDIS_URL", "WORKER_INTERVAL", "WORKER_BATCH_SIZE",
		"WORKER_ADVISORY_LOCK_ID", "SYNC_MODE", "API_BASE_URL",
		"WORKER_API_EMAIL", "WORKER_API_PASSWORD", "LOG_LEVEL",
	}
	values := map[string]string{}
	for _, key := range required {
		value := os.Getenv(key)
		if value == "" {
			return Config{}, fmt.Errorf("%s is required", key)
		}
		values[key] = value
	}

	interval, err := time.ParseDuration(values["WORKER_INTERVAL"])
	if err != nil || interval <= 0 {
		return Config{}, errors.New("WORKER_INTERVAL must be a positive duration")
	}
	batchSize, err := strconv.Atoi(values["WORKER_BATCH_SIZE"])
	if err != nil || batchSize <= 0 {
		return Config{}, errors.New("WORKER_BATCH_SIZE must be a positive integer")
	}
	lockID, err := strconv.ParseInt(values["WORKER_ADVISORY_LOCK_ID"], 10, 64)
	if err != nil {
		return Config{}, errors.New("WORKER_ADVISORY_LOCK_ID must be an integer")
	}
	if values["SYNC_MODE"] != "db" && values["SYNC_MODE"] != "api" {
		return Config{}, errors.New("SYNC_MODE must be db or api")
	}
	if _, err := url.ParseRequestURI(values["API_BASE_URL"]); err != nil {
		return Config{}, errors.New("API_BASE_URL must be a URL")
	}

	return Config{
		DatabaseURL:          normalizeDatabaseURL(values["DATABASE_URL"]),
		RedisURL:             values["REDIS_URL"],
		WorkerInterval:       interval,
		WorkerBatchSize:      batchSize,
		WorkerAdvisoryLockID: lockID,
		SyncMode:             values["SYNC_MODE"],
		APIBaseURL:           values["API_BASE_URL"],
		WorkerAPIEmail:       values["WORKER_API_EMAIL"],
		WorkerAPIPassword:    values["WORKER_API_PASSWORD"],
		LogLevel:             values["LOG_LEVEL"],
	}, nil
}

func normalizeDatabaseURL(databaseURL string) string {
	return strings.Replace(databaseURL, "pgsql://", "postgres://", 1)
}
