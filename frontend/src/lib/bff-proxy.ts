import { NextResponse } from "next/server";
import { apiBaseUrl } from "@/lib/env";
import { authCookies, expiredAuthCookies, RENEWAL_COOKIE, SESSION_COOKIE } from "@/lib/auth-cookies";
import type { AuthTokenPair } from "@/lib/types";

type ProxyOptions = {
  method: string;
  body?: string;
  noStore?: boolean;
};

export async function proxyBackend(path: string, options: ProxyOptions): Promise<NextResponse> {
  const first = await callBackend(path, options);
  if (first.response.status !== 401) {
    return first.response;
  }
  const refreshed = await refresh(first.renewal);
  if (!refreshed) {
    return clear(401);
  }
  const second = await callBackend(path, options, refreshed.access_token);
  const response = second.response;
  for (const cookie of authCookies(refreshed)) {
    response.cookies.set(cookie);
  }
  return response;
}

async function callBackend(path: string, options: ProxyOptions, tokenOverride?: string) {
  const { cookies } = await import("next/headers");
  const cookieStore = await cookies();
  const session = tokenOverride ?? cookieStore.get(SESSION_COOKIE)?.value;
  const renewal = cookieStore.get(RENEWAL_COOKIE)?.value;
  if (!session) {
    return { response: clear(401), renewal };
  }
  const backend = await fetch(`${apiBaseUrl()}${path}`, {
    method: options.method,
    cache: options.noStore ? "no-store" : "no-store",
    headers: {
      Accept: "application/json",
      ...(options.body ? { "Content-Type": "application/json" } : {}),
      Authorization: `Bearer ${session}`,
    },
    body: options.body,
  });
  const body = await backend.text();
  return {
    renewal,
    response: new NextResponse(body, {
      status: backend.status,
      headers: { "Content-Type": backend.headers.get("Content-Type") ?? "application/json" },
    }),
  };
}

async function refresh(renewal: string | undefined): Promise<AuthTokenPair | null> {
  if (!renewal) return null;
  const response = await fetch(`${apiBaseUrl()}/auth/refresh`, {
    method: "POST",
    cache: "no-store",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ refresh_token: renewal }),
  });
  if (!response.ok) return null;
  return (await response.json()) as AuthTokenPair;
}

function clear(status: number) {
  const response = NextResponse.json({ ok: false }, { status });
  for (const cookie of expiredAuthCookies()) {
    response.cookies.set(cookie);
  }
  return response;
}
