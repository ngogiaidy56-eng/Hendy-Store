import type { Metadata } from "next";
import "./globals.css";

const siteUrl = process.env.NEXT_PUBLIC_SITE_URL || "https://hendystore.onrender.com";

export const metadata: Metadata = {
  metadataBase: new URL(siteUrl),
  title: {
    default: "Hendy Store — Khám phá ứng dụng",
    template: "%s | Hendy Store"
  },
  description: "Khám phá ứng dụng, công cụ và trò chơi được tuyển chọn tại Hendy Store.",
  applicationName: "Hendy Store",
  alternates: { canonical: "/" },
  openGraph: {
    type: "website",
    locale: "vi_VN",
    siteName: "Hendy Store",
    title: "Hendy Store — Khám phá ứng dụng",
    description: "Ứng dụng, công cụ và trò chơi trong một không gian duy nhất."
  },
  robots: { index: true, follow: true }
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="vi">
      <body>
        <header className="site-header">
          <a className="brand" href="/">Hendy <b>Store</b></a>
          <nav aria-label="Điều hướng chính">
            <a href="/">Trang chủ</a>
            <a href="/#apps">Ứng dụng</a>
            <a href="https://hendystore.onrender.com/library/">Thư viện</a>
          </nav>
        </header>
        {children}
        <footer className="site-footer">
          <div>© {new Date().getFullYear()} Hendy Store</div>
          <nav aria-label="Liên kết pháp lý">
            <a href="https://hendystore.onrender.com/privacy/">Quyền riêng tư</a>
            <a href="https://hendystore.onrender.com/terms-of-use/">Điều khoản</a>
            <a href="https://hendystore.onrender.com/developer/">Nhà phát triển</a>
          </nav>
        </footer>
      </body>
    </html>
  );
}
