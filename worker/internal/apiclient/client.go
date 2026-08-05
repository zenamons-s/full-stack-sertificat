package apiclient

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"time"
)

type Client struct {
	baseURL  string
	email    string
	password string
	http     *http.Client
	logger   *slog.Logger
	mu       sync.Mutex
	access   string
}

func New(baseURL, email, password string, logger *slog.Logger) *Client {
	return &Client{
		baseURL:  strings.TrimRight(baseURL, "/"),
		email:    email,
		password: password,
		http:     &http.Client{Timeout: 10 * time.Second},
		logger:   logger,
	}
}

func (c *Client) Expire(ctx context.Context) (int, error) {
	req, err := c.newRequest(ctx)
	if err != nil {
		return 0, err
	}
	resp, err := c.http.Do(req)
	if err != nil {
		return 0, err
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusUnauthorized {
		c.mu.Lock()
		c.access = ""
		c.mu.Unlock()
		req, err = c.newRequest(ctx)
		if err != nil {
			return 0, err
		}
		resp, err = c.http.Do(req)
		if err != nil {
			return 0, err
		}
		defer resp.Body.Close()
	}

	if resp.StatusCode < http.StatusOK || resp.StatusCode >= http.StatusMultipleChoices {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return 0, fmt.Errorf("api expire failed: status=%d body=%s", resp.StatusCode, string(body))
	}

	var payload struct {
		Updated int `json:"updated"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&payload); err != nil {
		return 0, err
	}
	return payload.Updated, nil
}

func (c *Client) newRequest(ctx context.Context) (*http.Request, error) {
	token, err := c.token(ctx)
	if err != nil {
		return nil, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+"/worker/expire", nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+token)
	return req, nil
}

func (c *Client) token(ctx context.Context) (string, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.access != "" {
		return c.access, nil
	}

	body, err := json.Marshal(map[string]string{"email": c.email, "password": c.password})
	if err != nil {
		return "", err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+"/auth/login", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode < http.StatusOK || resp.StatusCode >= http.StatusMultipleChoices {
		return "", fmt.Errorf("worker api login failed: status=%d", resp.StatusCode)
	}

	var payload struct {
		AccessToken string `json:"access_token"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&payload); err != nil {
		return "", err
	}
	if payload.AccessToken == "" {
		return "", fmt.Errorf("worker api login returned empty access token")
	}
	c.access = payload.AccessToken
	c.logger.Info("worker api token refreshed")
	return c.access, nil
}
