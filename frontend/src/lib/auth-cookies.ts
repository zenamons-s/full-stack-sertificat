import type { ResponseCookie } from "next/dist/compiled/@edge-runtime/cookies";
import type { AuthTokenPair } from "@/lib/types";

export const SESSION_COOKIE = "gc_session";
export const RENEWAL_COOKIE = "gc_renewal";

const refreshMaxAge = 60 * 60 * 24 * 14;

function baseOptions(maxAge: number): Partial<ResponseCookie> {
  return {
    httpOnly: true,
    sameSite: "lax",
    secure: process.env.NODE_ENV === "production",
    path: "/",
    maxAge,
  };
}

export function sessionCookie(token: string, maxAge: number): Partial<ResponseCookie> & { name: string; value: string } {
  return { name: SESSION_COOKIE, value: token, ...baseOptions(maxAge) };
}

export function renewalCookie(token: string): Partial<ResponseCookie> & { name: string; value: string } {
  return { name: RENEWAL_COOKIE, value: token, ...baseOptions(refreshMaxAge) };
}

export function authCookies(tokens: AuthTokenPair) {
  return [sessionCookie(tokens.access_token, tokens.expires_in), renewalCookie(tokens.refresh_token)] as const;
}

export function expiredAuthCookies() {
  return [
    { name: SESSION_COOKIE, value: "", ...baseOptions(0) },
    { name: RENEWAL_COOKIE, value: "", ...baseOptions(0) },
  ] as const;
}
