import { Suspense } from "react";
import { LoginForm } from "@/components/login-form";

export default function LoginPage() {
  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
      <section className="w-full max-w-sm rounded-md border bg-white p-6 shadow-sm">
        <h1 className="text-xl font-semibold">Вход</h1>
        <p className="mt-1 text-sm text-slate-600">Используйте тестовую учётную запись.</p>
        <Suspense>
          <LoginForm />
        </Suspense>
      </section>
    </main>
  );
}
