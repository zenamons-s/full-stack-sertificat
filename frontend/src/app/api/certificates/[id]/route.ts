import { revalidatePath } from "next/cache";
import { NextRequest } from "next/server";
import { proxyBackend } from "@/lib/bff-proxy";

type Params = { params: Promise<{ id: string }> };

export async function GET(_request: NextRequest, { params }: Params) {
  const { id } = await params;
  return proxyBackend(`/certificates/${encodeURIComponent(id)}`, { method: "GET", noStore: true });
}

export async function PATCH(request: NextRequest, { params }: Params) {
  const { id } = await params;
  const response = await proxyBackend(`/certificates/${encodeURIComponent(id)}`, { method: "PATCH", body: await request.text() });
  if (response.ok) {
    revalidatePath("/certificates");
    revalidatePath(`/certificates/${id}/edit`);
  }
  return response;
}

export async function DELETE(_request: NextRequest, { params }: Params) {
  const { id } = await params;
  const response = await proxyBackend(`/certificates/${encodeURIComponent(id)}`, { method: "DELETE" });
  if (response.ok) {
    revalidatePath("/certificates");
  }
  return response;
}
