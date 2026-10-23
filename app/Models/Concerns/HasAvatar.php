<?php

namespace App\Models\Concerns;

use App\Services\Avatars\AvatarPath;

/**
 * Единая модель хранения аватаров: avatar_url — публичный URL для фронтенда,
 * avatar_disk/avatar_path — точная ссылка на принадлежащий приложению файл.
 * Старые записи (avatar_disk/avatar_path = null) разбираются из avatar_url.
 *
 * @property string|null $avatar_url
 * @property string|null $avatar_disk
 * @property string|null $avatar_path
 */
trait HasAvatar
{
    /**
     * Каталог на диске, в котором хранятся аватары этой модели.
     */
    public function avatarDirectory(): string
    {
        return 'avatars/';
    }

    /**
     * Ссылка на текущий файл аватара.
     *
     * owned=true только для файлов на принадлежащих приложению дисках
     * внутри разрешённых avatar-префиксов. Внешние URL и чужие пути
     * возвращаются с owned=false и никогда не удаляются.
     *
     * @return array{owned: bool, disk: string|null, path: string|null}
     */
    public function avatarReference(): array
    {
        $disk = $this->avatar_disk;
        $path = $this->avatar_path;

        if (is_string($disk) && is_string($path)) {
            $owned = in_array($disk, AvatarPath::ownedDisks(), true)
                && AvatarPath::isAllowed($path);

            return ['owned' => $owned, 'disk' => $disk, 'path' => $path];
        }

        $legacyPath = AvatarPath::fromLegacyUrl($this->avatar_url);

        if ($legacyPath === null) {
            return ['owned' => false, 'disk' => null, 'path' => null];
        }

        return [
            'owned' => AvatarPath::isAllowed($legacyPath),
            'disk' => 'public',
            'path' => $legacyPath,
        ];
    }
}
