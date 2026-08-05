import { NextResponse } from "next/server";
import { apiBaseUrl } from "@/lib/env";
import { authCookies, expiredAuthCookies, RENEWAL_COOKIE } from "@/lib/auth-cookies";
import type { AuthTokenPair } from "@/lib/types";

export async function POST(request: Request) {
  const cookie = request.headers.get("cookie") ?? "";
  const renewal = cookie
    .split(";")
    .map((part) => part.trim())
    .find((part) => part.startsWith(`${RENEWAL_COOKIE}=`))
    ?.slice(RENEWAL_COOKIE.length + 1);

  if (!renewal) {
    return clear(401);
  }

  const backend = await fetch(`${apiBaseUrl()}/auth/refresh`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ refresh_token: decodeURIComponent(renewal) }),
    cache: "no-store",
  });
  if (!backend.ok) {
    return clear(backend.status);
  }
  const tokens = (await backend.json()) as AuthTokenPair;
  const response = NextResponse.json({ ok: true });
  for (const item of authCookies(tokens)) {
    response.cookies.set(item);
  }
  return response;
}

function clear(status: number) {
  const response = NextResponse.json({ ok: false }, { status });
  for (const item of expiredAuthCookies()) {
    response.cookies.set(item);
  }
  return response;
}
