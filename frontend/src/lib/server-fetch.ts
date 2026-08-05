import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { apiBaseUrl } from "@/lib/env";
import { SESSION_COOKIE } from "@/lib/auth-cookies";
import { ApiProblemError, parseProblemResponse } from "@/lib/problem-details";

type ServerFetchOptions = RequestInit & {
  noStore?: boolean;
};

export async function serverFetch<T>(path: string, options: ServerFetchOptions = {}): Promise<T> {
  const cookieStore = await cookies();
  const token = cookieStore.get(SESSION_COOKIE)?.value;
  if (!token) {
    redirect("/login");
  }
  const response = await fetch(`${apiBaseUrl()}${path}`, {
    ...options,
    cache: options.noStore ? "no-store" : options.cache,
    headers: {
      Accept: "application/json",
      ...(options.body ? { "Content-Type": "application/json" } : {}),
      ...options.headers,
      Authorization: `Bearer ${token}`,
    },
  });
  if (response.status === 401) {
    redirect("/login");
  }
  if (!response.ok) {
    throw new ApiProblemError(await parseProblemResponse(response));
  }
  return (await response.json()) as T;
}
