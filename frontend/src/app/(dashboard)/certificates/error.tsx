"use client";

export default function CertificatesError({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <main className="mx-auto flex min-h-screen max-w-xl flex-col justify-center px-6">
      <h1 className="text-2xl font-semibold">Не удалось загрузить интерфейс</h1>
      <p className="mt-3 text-sm text-slate-600">Проверьте соединение и попробуйте ещё раз.</p>
      <button className="mt-6 rounded-md bg-blue-600 px-4 py-2 text-white" onClick={reset}>
        Повторить
      </button>
    </main>
  );
}
