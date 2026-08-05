import { NextRequest, NextResponse } from "next/server";
import { SESSION_COOKIE } from "@/lib/auth-cookies";
import { safeNext } from "@/lib/safe-next";

export function middleware(request: NextRequest) {
  const { pathname, search } = request.nextUrl;
  const hasSession = request.cookies.has(SESSION_COOKIE);

  if (pathname === "/login" && hasSession) {
    return NextResponse.redirect(new URL("/certificates", request.url));
  }

  if ((pathname === "/certificates" || pathname.startsWith("/certificates/")) && !hasSession) {
    const login = new URL("/login", request.url);
    login.searchParams.set("next", safeNext(`${pathname}${search}`));
    return NextResponse.redirect(login);
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/login", "/certificates/:path*"],
};
