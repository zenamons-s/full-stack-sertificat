import { describe, expect, it } from "vitest";
import { applyProblemErrors, fallbackProblem, parseProblemResponse, problemFromNetworkError } from "@/lib/problem-details";

function response(body: string, status = 422, contentType = "application/problem+json") {
  return new Response(body, { status, headers: { "Content-Type": contentType } });
}

describe("problem-details", () => {
  it("parses 422 with multiple fields", async () => {
    const problem = await parseProblemResponse(response(JSON.stringify({
      type: "https://api.local/problems/validation-error",
      title: "Validation failed",
      status: 422,
      detail: "Request payload is invalid",
      instance: "/api/v1/certificates",
      request_id: "req",
      errors: { title: ["empty"], price_minor: ["positive"] },
    })));
    expect(problem.errors?.title).toEqual(["empty"]);
    expect(problem.errors?.price_minor).toEqual(["positive"]);
  });

  it("applies problem errors to matching form fields", () => {
    const calls: Array<{ field: string; message: string }> = [];

    applyProblemErrors({
      type: "https://api.local/problems/validation-error",
      title: "Validation failed",
      status: 422,
      detail: "Request payload is invalid",
      instance: "/api/v1/certificates",
      request_id: "req",
      errors: { title: ["empty"], price_minor: ["positive", "integer"] },
    }, (field, error) => {
      calls.push({ field, message: String(error.message) });
    });

    expect(calls).toEqual([
      { field: "title", message: "empty" },
      { field: "price_minor", message: "positive\ninteger" },
    ]);
  });

  it("parses 409 current_state", async () => {
    const problem = await parseProblemResponse(response(JSON.stringify({
      type: "https://api.local/problems/conflict",
      title: "Conflict",
      status: 409,
      detail: "Changed",
      instance: "/api/v1/certificates/1",
      request_id: "req",
      current_state: { id: 1, title: "A", price_minor: 100, currency: "RUB", price_formatted: "1,00 ₽", expires_at: "2027-01-01T00:00:00Z", status: "expired", version: 2, created_at: "2026-01-01T00:00:00Z", updated_at: "2026-01-01T00:00:00Z", deleted_at: null },
    }), 409));
    expect(problem.current_state?.status).toBe("expired");
    expect(problem.current_state?.version).toBe(2);
  });

  it("falls back on invalid json", async () => {
    const problem = await parseProblemResponse(response("<html>", 500, "text/html"));
    expect(problem.status).toBe(500);
    expect(problem.detail).toContain("неразборчивый");
  });

  it("falls back on network error", () => {
    const problem = problemFromNetworkError(new Error("connection refused"));
    expect(problem.status).toBe(0);
    expect(problem.detail).toBe("connection refused");
  });

  it("falls back on empty response", async () => {
    expect(await parseProblemResponse(response("", 503))).toEqual(fallbackProblem(503, "Пустой ответ сервера"));
  });
});
