import { createCipheriv, createDecipheriv, createHash, randomBytes } from 'node:crypto';

function encryptionKey(): Buffer {
    const configured = process.env.QR_TOKEN_ENCRYPTION_KEY;
    if (!configured && process.env.NODE_ENV === 'production') {
        throw new Error('QR_TOKEN_ENCRYPTION_KEY is required in production.');
    }
    const material = configured ?? `local-only:${process.cwd()}:skykite-qr`;
    return createHash('sha256').update('skykite-qr-token:v1:').update(material).digest();
}

export function encryptQrToken(token: string): string {
    const iv = randomBytes(12);
    const cipher = createCipheriv('aes-256-gcm', encryptionKey(), iv);
    const ciphertext = Buffer.concat([cipher.update(token, 'utf8'), cipher.final()]);
    return ['v1', iv.toString('base64url'), cipher.getAuthTag().toString('base64url'), ciphertext.toString('base64url')].join('.');
}

export function decryptQrToken(value: string): string {
    const [version, ivValue, tagValue, ciphertextValue] = value.split('.');
    if (version !== 'v1' || !ivValue || !tagValue || !ciphertextValue) throw new Error('Unsupported encrypted QR token format.');
    const decipher = createDecipheriv('aes-256-gcm', encryptionKey(), Buffer.from(ivValue, 'base64url'));
    decipher.setAuthTag(Buffer.from(tagValue, 'base64url'));
    return Buffer.concat([decipher.update(Buffer.from(ciphertextValue, 'base64url')), decipher.final()]).toString('utf8');
}
