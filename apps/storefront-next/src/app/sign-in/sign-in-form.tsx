"use client";

import { useEffect, useState, type FormEvent } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import Link from "next/link";
import { authClient } from "@/lib/auth/client";

type PublicAuthConfig = { neonAuthEnabled?: boolean; neonAuthPublicSignup?: boolean; googleOAuthEnabled?: boolean };
type ApiPayload = { ok?: boolean; error?: string; data?: { user?: { id: number; name: string; email: string; role: string } } };

async function readPublicAuthConfig(): Promise<PublicAuthConfig> {
  const response = await fetch("/api.php?action=bootstrap", {
    credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" },
  });
  const payload = await response.json() as { ok?: boolean; data?: PublicAuthConfig };
  if (!response.ok || !payload.ok || !payload.data) throw new Error("Không thể tải cấu hình đăng nhập từ Hendy Store.");
  return payload.data;
}

async function verifyCustomerApiSession(): Promise<void> {
  const tokenResult = await authClient.token();
  const token = tokenResult.data?.token;
  if (tokenResult.error || !token) throw new Error("Neon Auth chưa cấp JWT. Hãy xác minh email hoặc đăng nhập lại.");
  const response = await fetch("/api.php?action=bootstrap", {
    credentials: "same-origin", cache: "no-store",
    headers: { Accept: "application/json", Authorization: "Bearer " + token },
  });
  const payload = await response.json() as ApiPayload;
  if (!response.ok || !payload.ok || !payload.data?.user) {
    throw new Error(payload.error || "Backend chưa xác thực được Neon Auth JWT. Kiểm tra JWKS và issuer.");
  }
  if (payload.data.user.role !== "customer") {
    throw new Error("Tài khoản này không thuộc khu vực khách hàng. Quản trị viên cần dùng /admin/ trên backend.");
  }
}

function safeRedirect(value: string | null): string {
  if (!value || !value.startsWith("/") || value.startsWith("//")) return "/account";
  try {
    const target = new URL(value, window.location.origin);
    if (target.origin !== window.location.origin) return "/account";
    return target.pathname + target.search + target.hash;
  } catch {
    return "/account";
  }
}

export function SignInForm() {
  const router = useRouter();
  const search = useSearchParams();
  const [config, setConfig] = useState<PublicAuthConfig | null>(null);
  const [mode, setMode] = useState<"login" | "register">("login");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState(false);

  useEffect(() => {
    readPublicAuthConfig().then(setConfig).catch((issue: Error) => { setMessage(issue.message); setError(true); });
  }, []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const email = String(form.get("email") || "").trim().toLowerCase();
    const password = String(form.get("password") || "");
    const name = String(form.get("name") || "").trim();
    setBusy(true); setMessage("Đang kết nối Neon Auth…"); setError(false);
    try {
      if (!config?.neonAuthEnabled) throw new Error("Neon Auth chưa được cấu hình trên backend.");
      if (mode === "register") {
        if (!config.neonAuthPublicSignup) throw new Error("Đăng ký khách hàng đang tạm khóa.");
        const result = await authClient.signUp.email({ name, email, password });
        if (result.error) throw new Error(result.error.message || "Không thể tạo tài khoản.");
        setMode("login");
        setMessage("Đã gửi yêu cầu tạo tài khoản. Hãy kiểm tra email và hoàn tất xác minh trước khi đăng nhập.");
        return;
      }
      const result = await authClient.signIn.email({ email, password });
      if (result.error) throw new Error(result.error.message || "Email hoặc mật khẩu không chính xác.");
      await verifyCustomerApiSession();
      setMessage("Đăng nhập thành công.");
      router.replace(safeRedirect(search.get("redirect")));
      router.refresh();
    } catch (issue) {
      setError(true);
      setMessage(issue instanceof Error ? issue.message : "Đăng nhập thất bại.");
    } finally {
      setBusy(false);
    }
  }

  async function signInGoogle() {
    setBusy(true); setError(false); setMessage("Đang chuyển đến Google…");
    try {
      if (!config?.googleOAuthEnabled) throw new Error("Đăng nhập Google chưa được bật.");
      await authClient.signIn.social({
        provider: "google",
        callbackURL: new URL("/account", window.location.origin).toString(),
      });
    } catch (issue) {
      setBusy(false); setError(true);
      setMessage(issue instanceof Error ? issue.message : "Không thể bắt đầu Google OAuth.");
    }
  }

  return (
    <section className="auth-shell">
      <div className="auth-card">
        <span className="kicker">HENDY STORE · ACCOUNT</span>
        <h1>{mode === "login" ? "Chào mừng trở lại" : "Tạo tài khoản khách hàng"}</h1>
        <p>Đăng nhập để quản lý thư viện ứng dụng, lịch sử tải và hồ sơ cá nhân. Tài khoản quản trị sử dụng trang Admin riêng.</p>
        {config?.googleOAuthEnabled && mode === "login" ? (
          <button className="auth-google" type="button" disabled={busy} onClick={signInGoogle}>Tiếp tục với Google</button>
        ) : null}
        <form className="auth-form" onSubmit={submit}>
          {mode === "register" ? <label>Họ và tên<input name="name" minLength={2} maxLength={80} autoComplete="name" required /></label> : null}
          <label>Email<input name="email" type="email" autoComplete="email" maxLength={190} required /></label>
          <label>Mật khẩu<input name="password" type="password" autoComplete={mode === "login" ? "current-password" : "new-password"} minLength={12} maxLength={72} required /></label>
          <button className="auth-submit" type="submit" disabled={busy || !config?.neonAuthEnabled}>
            {busy ? "Đang xử lý…" : mode === "login" ? "Đăng nhập →" : "Tạo tài khoản →"}
          </button>
        </form>
        <p className={"auth-message" + (error ? " auth-error" : "")} role="status" aria-live="polite">{message}</p>
        {config?.neonAuthPublicSignup ? (
          <p className="auth-switch">
            {mode === "login" ? "Chưa có tài khoản? " : "Đã có tài khoản? "}
            <button className="auth-link" type="button" onClick={() => { setMode(mode === "login" ? "register" : "login"); setMessage(""); setError(false); }}>
              {mode === "login" ? "Đăng ký" : "Đăng nhập"}
            </button>
          </p>
        ) : null}
        <p className="auth-switch"><Link className="auth-link" href="/">← Trở lại cửa hàng</Link></p>
      </div>
    </section>
  );
}
