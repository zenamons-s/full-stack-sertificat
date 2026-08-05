package expirer

import (
	"time"

	"gift-certificates/worker/internal/clock"
)

func isExpired(expiresAt time.Time, clock clock.Clock) bool {
	return !expiresAt.After(clock.Now())
}
