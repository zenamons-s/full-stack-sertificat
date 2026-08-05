import { revalidatePath } from "next/cache";
import { NextRequest } from "next/server";
import { proxyBackend } from "@/lib/bff-proxy";

export async function GET(request: NextRequest) {
  return proxyBackend(`/certificates${request.nextUrl.search}`, { method: "GET", noStore: true });
}

export async function POST(request: NextRequest) {
  const response = await proxyBackend("/certificates", { method: "POST", body: await request.text() });
  if (response.ok) {
    revalidatePath("/certificates");
  }
  return response;
}
