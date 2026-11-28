import type { Metadata } from "next";
import { Suspense } from "react";
import { SignInForm } from "./sign-in-form";

export const metadata: Metadata = { title: "Đăng nhập", robots: { index: false, follow: false } };
export const dynamic = "force-dynamic";

export default function SignInPage() {
  return <Suspense fallback={<main className="auth-shell"><div className="auth-card">Đang tải biểu mẫu đăng nhập…</div></main>}>
    <SignInForm />
  </Suspense>;
}
