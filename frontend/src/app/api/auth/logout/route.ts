import { NextResponse } from "next/server";
import { expiredAuthCookies } from "@/lib/auth-cookies";

export function POST() {
  const response = NextResponse.json({ ok: true });
  for (const cookie of expiredAuthCookies()) {
    response.cookies.set(cookie);
  }
  return response;
}
