import type { ReactNode } from "react";
import { AppShell } from "@/components/app-shell";
import { serverFetch } from "@/lib/server-fetch";
import type { User } from "@/lib/types";

export const dynamic = "force-dynamic";

export default async function DashboardLayout({ children }: { children: ReactNode }) {
  const user = await serverFetch<User>("/auth/me", { noStore: true });

  return <AppShell userEmail={user.email}>{children}</AppShell>;
}
