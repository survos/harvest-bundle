<?php

declare(strict_types=1);

namespace Survos\HarvestBundle\Catalog;

use Survos\DataContracts\Path\DataPaths;
use Survos\FolioBundle\Service\DuckDbFolioConnection;
use Survos\FolioBundle\Service\DuckDbFolioLocatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A dfolio in the application's data directory is read before the published address. Without a local copy the
 * address comes from the consumer's synchronized metadata; never from producer paths.
 */
final readonly class HarvestDuckDbFolioLocator implements DuckDbFolioLocatorInterface
{
    public function __construct(private HarvestCatalog $catalog, private RequestStack $requests,
        private string $defaultLocale, private ?DataPaths $paths = null)
    {
    }

    public function locate(string $folioCode): string
    {
        DuckDbFolioConnection::validate($folioCode);
        $snapshots = $this->catalog->remoteSnapshots($folioCode);
        $request = $this->requests->getMainRequest();
        $session = $request?->hasSession() ? $request->getSession() : null;
        $sessionKey = 'dfolio_language.'.$folioCode;
        $locale = $request?->query->getString('language') ?: ($session?->get($sessionKey) ?? $this->defaultLocale);
        $local = $this->local($folioCode, $locale, $snapshots);
        if (null === $local && !isset($snapshots[$locale])) {
            throw new NotFoundHttpException([] === $snapshots ? 'No local dfolio and no published remote DuckDB snapshot. Run folio:pull, or harvest:sync after publication.' : 'Language not published for this dataset: '.$locale);
        }
        $session?->set($sessionKey, $locale);

        return $local ?? $snapshots[$locale]->uri;
    }

    /**
     * The local file has the published file's name: <code>.dfolio for the source language, <code>.<locale>.dfolio
     * for a translation. With no publication to name it, only the source-language file is known.
     *
     * @param array<string,\Survos\FolioBundle\Catalog\RemoteDuckDbSnapshot> $snapshots
     */
    private function local(string $folioCode, string $locale, array $snapshots): ?string
    {
        if (null === $this->paths) {
            return null;
        }
        $source = $this->paths->folioFile($folioCode, 'dfolio');
        if (isset($snapshots[$locale])) {
            $publishedName = basename($snapshots[$locale]->uri);
            $legacyName = 1 === preg_match('/^[a-f0-9]{64}\.dfolio$/D', $publishedName);
            // folio:pull and local builds use the canonical dataset path, not a remote revision hash.
            $file = $legacyName
                ? ($locale === $this->defaultLocale ? $source : substr($source, 0, -strlen('.dfolio')).'.'.$locale.'.dfolio')
                : dirname($source).'/'.$publishedName;
            if (is_file($file) && is_readable($file)) {
                return $file;
            }
            // Retain compatibility with previously cached revision-named snapshots.
            $file = dirname($source).'/'.$publishedName;
        } else {
            $file = [] === $snapshots && $locale === $this->defaultLocale ? $source : null;
        }

        return null !== $file && is_file($file) && is_readable($file) ? $file : null;
    }
}
