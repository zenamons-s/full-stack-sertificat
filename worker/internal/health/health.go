package health

import (
	"context"
	"log/slog"
	"net/http"
	"time"
)

type Pinger interface {
	Ping(ctx context.Context) error
}

func NewServer(addr string, pinger Pinger, logger *slog.Logger) *http.Server {
	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) {
		ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		defer cancel()
		if err := pinger.Ping(ctx); err != nil {
			logger.Error("worker health db check failed", "error", err.Error())
			w.Header().Set("Content-Type", "application/json")
			w.WriteHeader(http.StatusServiceUnavailable)
			_, _ = w.Write([]byte(`{"status":"error","db":"error"}`))
			return
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"status":"ok","db":"ok"}`))
	})
	return &http.Server{Addr: addr, Handler: mux, ReadHeaderTimeout: 5 * time.Second}
}
