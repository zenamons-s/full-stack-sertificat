import type { FieldValues, Path, UseFormSetError } from "react-hook-form";
import type { Certificate, ProblemDetails } from "@/lib/types";

export type ParsedProblem = ProblemDetails & {
  current_state?: Certificate;
};

export async function parseProblemResponse(response: Response): Promise<ParsedProblem> {
  const text = await response.text();
  if (text.trim() === "") {
    return fallbackProblem(response.status, "Пустой ответ сервера");
  }

  try {
    const value: unknown = JSON.parse(text);
    if (isProblem(value)) {
      return value;
    }
    return fallbackProblem(response.status, "Сервер вернул неожиданный JSON");
  } catch {
    return fallbackProblem(response.status, "Сервер вернул неразборчивый ответ");
  }
}

export function problemFromNetworkError(error: unknown): ParsedProblem {
  const message = error instanceof Error ? error.message : "Сетевая ошибка";
  return fallbackProblem(0, message);
}

export function fallbackProblem(status: number, detail: string): ParsedProblem {
  return {
    type: "about:blank",
    title: "Request failed",
    status,
    detail,
    instance: "",
    request_id: "",
  };
}

export function applyProblemErrors<T extends FieldValues>(problem: ParsedProblem, setError: UseFormSetError<T>): void {
  const errors: Record<string, string[]> = problem.errors ?? {};
  for (const [field, messages] of Object.entries(errors)) {
    setError(field as Path<T>, { type: "server", message: messages.join("\n") });
  }
}

export class ApiProblemError extends Error {
  constructor(public readonly problem: ParsedProblem) {
    super(problem.detail);
  }
}

function isProblem(value: unknown): value is ParsedProblem {
  if (!value || typeof value !== "object") return false;
  const object = value as Record<string, unknown>;
  return (
    typeof object.type === "string" &&
    typeof object.title === "string" &&
    typeof object.status === "number" &&
    typeof object.detail === "string" &&
    typeof object.instance === "string" &&
    typeof object.request_id === "string"
  );
}
