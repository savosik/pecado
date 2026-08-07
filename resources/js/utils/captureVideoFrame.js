/**
 * Пропорция кадра для фото товара и некондиции — 1 : 1.5 (ширина : высота).
 *
 * Одно значение на видоискатель, обрезку снимка и превью: кладовщик снимает
 * ровно то, что видит, а карточка на сайте получает предсказуемо вертикальные
 * фотографии.
 */
export const PHOTO_ASPECT_RATIO = 1 / 1.5;

/**
 * Снять текущий кадр <video> в File, обрезав его по центру до нужной пропорции.
 *
 * Камера почти всегда отдаёт горизонтальный кадр, а видоискатель на странице
 * вертикальный (objectFit: cover) — без такой же обрезки снимок не совпал бы
 * с тем, что кладовщик видел на экране.
 *
 * @param {HTMLVideoElement|null} video
 * @param {object} [options]
 * @param {number|null} [options.aspectRatio] - пропорция ширина/высота; null — без обрезки
 * @param {number} [options.quality] - качество JPEG
 * @param {string} [options.prefix] - префикс имени файла
 * @returns {Promise<File|null>} File или null, если кадра ещё нет
 */
export function captureVideoFrame(video, { aspectRatio = null, quality = 0.9, prefix = 'photo' } = {}) {
    return new Promise((resolve) => {
        const width = video?.videoWidth;
        const height = video?.videoHeight;

        if (!video || !width || !height) {
            resolve(null);

            return;
        }

        let sourceWidth = width;
        let sourceHeight = height;

        if (aspectRatio) {
            if (width / height > aspectRatio) {
                sourceWidth = Math.round(height * aspectRatio);
            } else {
                sourceHeight = Math.round(width / aspectRatio);
            }
        }

        const offsetX = Math.round((width - sourceWidth) / 2);
        const offsetY = Math.round((height - sourceHeight) / 2);

        const canvas = document.createElement('canvas');
        canvas.width = sourceWidth;
        canvas.height = sourceHeight;
        canvas
            .getContext('2d')
            .drawImage(video, offsetX, offsetY, sourceWidth, sourceHeight, 0, 0, sourceWidth, sourceHeight);

        canvas.toBlob(
            (blob) => {
                if (!blob) {
                    resolve(null);

                    return;
                }

                const stamp = new Date().toISOString().replace(/[-:T.]/g, '').slice(0, 14);
                const suffix = Math.random().toString(36).slice(2, 6);

                resolve(
                    new File([blob], `${prefix}-${stamp}-${suffix}.jpg`, {
                        type: 'image/jpeg',
                        lastModified: Date.now(),
                    })
                );
            },
            'image/jpeg',
            quality
        );
    });
}
