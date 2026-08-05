package main

import (
	"context"
	"encoding/json"
	"flag"
	"net/http"
	"os"
	"os/signal"
	"time"
)

func main() {
	healthcheck := flag.Bool("healthcheck", false, "check local health endpoint")
	flag.Parse()

	if *healthcheck {
		checkHealth()
		return
	}

	interval, err := time.ParseDuration(getenv("WORKER_INTERVAL", "60s"))
	if err != nil {
		interval = time.Minute
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"status":"ok"}`))
	})

	server := &http.Server{Addr: ":8081", Handler: mux}
	go func() {
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			logJSON("worker health server failed", map[string]any{"error": err.Error()})
			os.Exit(1)
		}
	}()

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt)
	defer stop()

	ticker := time.NewTicker(interval)
	defer ticker.Stop()

	logJSON("worker tick, nothing to do", nil)
	for {
		select {
		case <-ctx.Done():
			shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			defer cancel()
			_ = server.Shutdown(shutdownCtx)
			return
		case <-ticker.C:
			logJSON("worker tick, nothing to do", nil)
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
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		os.Exit(1)
	}
}

func getenv(key string, fallback string) string {
	value := os.Getenv(key)
	if value == "" {
		return fallback
	}
	return value
}

func logJSON(message string, fields map[string]any) {
	payload := map[string]any{
		"level":   "info",
		"message": message,
		"time":    time.Now().UTC().Format(time.RFC3339),
	}
	for key, value := range fields {
		payload[key] = value
	}
	_ = json.NewEncoder(os.Stdout).Encode(payload)
}
