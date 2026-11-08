type CompressOptions = {
    maxWidth?: number;
    maxHeight?: number;
    quality?: number;
};

const isIco = (file: File) => /image\/(x-icon|vnd\.microsoft\.icon)/.test(file.type) || /\.ico$/i.test(file.name);

function loadImage(file: File): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const image = new Image();
        image.onload = () => {
            URL.revokeObjectURL(url);
            resolve(image);
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Could not read the image.'));
        };
        image.src = url;
    });
}

function toBlob(canvas: HTMLCanvasElement, type: string, quality: number): Promise<Blob | null> {
    return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

/**
 * Shrinks an image in the browser before it is uploaded, so the server never has to reject a
 * large logo. Returns the original file when it cannot be processed or is already small.
 */
export async function compressImage(file: File, options: CompressOptions = {}): Promise<File> {
    const { maxWidth = 1024, maxHeight = 1024, quality = 0.85 } = options;

    if (!file.type.startsWith('image/') || isIco(file)) {
        return file;
    }

    try {
        const image = await loadImage(file);
        const scale = Math.min(1, maxWidth / image.naturalWidth, maxHeight / image.naturalHeight);
        const width = Math.max(1, Math.round(image.naturalWidth * scale));
        const height = Math.max(1, Math.round(image.naturalHeight * scale));

        if (scale === 1 && file.size <= 512 * 1024) {
            return file;
        }

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');

        if (!context) {
            return file;
        }

        context.imageSmoothingEnabled = true;
        context.imageSmoothingQuality = 'high';
        context.drawImage(image, 0, 0, width, height);

        const outputType = file.type === 'image/png' || file.type === 'image/webp' ? 'image/png' : 'image/jpeg';
        const blob = await toBlob(canvas, outputType, quality);

        if (!blob || (blob.size >= file.size && scale === 1)) {
            return file;
        }

        const extension = outputType === 'image/png' ? 'png' : 'jpg';
        const name = file.name.replace(/\.[^.]+$/, '') + '.' + extension;

        return new File([blob], name, { type: outputType, lastModified: Date.now() });
    } catch {
        return file;
    }
}
