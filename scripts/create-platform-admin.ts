import "dotenv/config";
import { createInterface } from "node:readline/promises";
import { stdin, stdout } from "node:process";
import { hash } from "bcryptjs";
import { z } from "zod";
import { getDatabase, initializeDatabase } from "../src/server/db/client.js";
import { nowIso } from "../src/server/http.js";

async function promptHidden(label: string): Promise<string> {
    if (!stdin.isTTY || typeof stdin.setRawMode !== "function") {
        throw new Error(
            "Gizli parola girişi için komutu etkileşimli terminalde çalıştırın.",
        );
    }
    stdout.write(label);
    stdin.setRawMode(true);
    stdin.resume();
    stdin.setEncoding("utf8");
    let value = "";
    return new Promise((resolve, reject) => {
        const onData = (chunk: string) => {
            for (const key of chunk) {
                if (key === "\u0003") {
                    cleanup();
                    reject(new Error("İşlem iptal edildi."));
                    return;
                }
                if (key === "\r" || key === "\n") {
                    cleanup();
                    stdout.write("\n");
                    resolve(value);
                    return;
                }
                if (key === "\u007f" || key === "\b") {
                    value = value.slice(0, -1);
                    continue;
                }
                value += key;
            }
        };
        const cleanup = () => {
            stdin.off("data", onData);
            stdin.setRawMode(false);
            stdin.pause();
        };
        stdin.on("data", onData);
    });
}

async function main() {
    const email = z
        .string()
        .email()
        .max(254)
        .parse((await prompt("Süper admin e-posta: ")).trim().toLowerCase());
    const name = z
        .string()
        .trim()
        .min(2)
        .max(120)
        .parse(await prompt("Ad soyad: "));
    const password = z
        .string()
        .min(8)
        .max(200)
        .parse(
            await promptHidden(
                "Şifre (en az 8 karakter, ekranda gösterilmez): ",
            ),
        );
    const database = await getDatabase();
    try {
        await initializeDatabase(database);
        const existingAdmin = await database.execute({
            sql: "SELECT id FROM users WHERE role='platform_admin' LIMIT 1",
        });
        if (existingAdmin.rows.length)
            throw new Error(
                "En az bir süper admin zaten var. Yeni yönetici ekleme akışı ayrıca yetkilendirilmeli.",
            );
        const existingEmail = await database.execute({
            sql: "SELECT id FROM users WHERE email=? COLLATE NOCASE",
            args: [email],
        });
        if (existingEmail.rows.length)
            throw new Error("Bu e-posta adresi zaten kullanılıyor.");
        const now = nowIso();
        const created = await database.execute({
            sql: "INSERT INTO users (name,email,password_hash,role,is_active,created_at,updated_at) VALUES (?,?,?,'platform_admin',1,?,?) RETURNING id",
            args: [name, email, await hash(password, 12), now, now],
        });
        stdout.write(
            `Süper admin oluşturuldu. Kullanıcı ID: ${String(created.rows[0]?.id)}\n`,
        );
        stdout.write("Panele /giris adresinden giriş yapabilirsiniz.\n");
    } finally {
        await database.close();
    }
}

async function prompt(label: string): Promise<string> {
    const readline = createInterface({ input: stdin, output: stdout });
    try {
        return await readline.question(label);
    } finally {
        readline.close();
    }
}

main().catch((error: unknown) => {
    const message =
        error instanceof Error ? error.message : "Admin oluşturulamadı.";
    stdout.write(`${message}\n`);
    process.exitCode = 1;
});
