import { NextRequest, NextResponse } from "next/server";
import { apiBaseUrl } from "@/lib/env";
import { authCookies } from "@/lib/auth-cookies";
import type { AuthTokenPair } from "@/lib/types";

export async function POST(request: NextRequest) {
  const response = await fetch(`${apiBaseUrl()}/auth/login`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: await request.text(),
    cache: "no-store",
  });
  const text = await response.text();
  if (response.ok) {
    const tokens = JSON.parse(text) as AuthTokenPair;
    const next = NextResponse.json({ ok: true });
    for (const cookie of authCookies(tokens)) {
      next.cookies.set(cookie);
    }
    return next;
  }
  return new NextResponse(text, {
    status: response.status,
    headers: { "Content-Type": response.headers.get("Content-Type") ?? "application/json" },
  });
}
