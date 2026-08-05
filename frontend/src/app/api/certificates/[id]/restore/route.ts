import { revalidatePath } from "next/cache";
import { NextRequest } from "next/server";
import { proxyBackend } from "@/lib/bff-proxy";

type Params = { params: Promise<{ id: string }> };

export async function POST(_request: NextRequest, { params }: Params) {
  const { id } = await params;
  const response = await proxyBackend(`/certificates/${encodeURIComponent(id)}/restore`, { method: "POST" });
  if (response.ok) {
    revalidatePath("/certificates");
  }
  return response;
}
