<?php
declare(strict_types=1);

namespace Survos\PixieBundle\Controller;

use Survos\PixieBundle\Entity\Row;
use Survos\PixieBundle\Service\PixieService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route('/pixie/{pixieCode}/print', name: 'pixie_printer_')]
class PixiePrinterController extends AbstractController
{
    public function __construct(
        private readonly PixieService $pixieService,
        private Environment $twig,
    ) {}

    #[Route('/{core}', name: 'labels', defaults: ['core' => 'obj'])]
    public function labels(
        Request $request,
        string $pixieCode,
        string $core = 'obj'
    ): Response {
        $ctx = $this->pixieService->getReference($pixieCode);
        $em = $ctx->em;
        $coreEntity = $this->pixieService->getCore($core, $ctx->ownerRef);

        // Get parameters
        $limit = (int) $request->query->get('limit', 20);
        $offset = (int) $request->query->get('offset', 0);
        $template = $request->query->get('template', $pixieCode);
        $format = $request->query->get('format', 'html'); // html, pdf
        $columns = (int) $request->query->get('columns', 2);
        $search = $request->query->get('search');
        $ids = $request->query->get('ids'); // comma-separated list

        // Build query
        $qb = $em->getRepository(Row::class)->createQueryBuilder('r')
            ->where('r.core = :core')
            ->setParameter('core', $coreEntity)
            ->orderBy('r.idWithinCore', 'ASC');

        // Filter by specific IDs if provided
        if ($ids) {
            $idArray = array_map('trim', explode(',', $ids));
            $qb->andWhere('r.idWithinCore IN (:ids)')
                ->setParameter('ids', $idArray);
        }

        // Simple search filter
        if ($search) {
            $qb->andWhere('(r.label LIKE :search OR r.idWithinCore LIKE :search)')
                ->setParameter('search', '%' . $search . '%');
        }

        // Apply limit and offset
        $qb->setFirstResult($offset)
            ->setMaxResults($limit);

        $rows = $qb->getQuery()->getResult();

        // Count total for pagination
        $countQb = clone $qb;
        $total = $countQb->select('COUNT(r.id)')
            ->setFirstResult(0)
            ->setMaxResults(null)
            ->getQuery()
            ->getSingleScalarResult();

        // Template resolution
        $templateName = $this->resolveTemplateName($pixieCode, $core, $template);

        $context = [
            'sampleRow' => $rows[0]??null,
            'pixieCode' => $pixieCode,
            'core' => $core,
            'rows' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'columns' => $columns,
            'format' => $format,
            'template' => $template,
            'search' => $search,
            'ids' => $ids,
            'owner' => $ctx->owner ?? null,
            'config' => $ctx->config ?? null,
            'pagination' => [
                'current' => floor($offset / $limit) + 1,
                'total' => ceil($total / $limit),
                'hasNext' => ($offset + $limit) < $total,
                'hasPrev' => $offset > 0,
                'nextOffset' => ($offset + $limit) < $total ? $offset + $limit : null,
                'prevOffset' => $offset > 0 ? max(0, $offset - $limit) : null,
            ]
        ];

        if ($format === 'pdf') {
            return $this->renderPdf($templateName, $context);
        }

        return $this->render($templateName, $context);
    }

    #[Route('/{core}/preview', name: 'preview')]
    public function preview(
        Request $request,
        string $pixieCode,
        string $core = 'obj'
    ): Response {
        // Show available templates and sample data
        $ctx = $this->pixieService->getReference($pixieCode);
        $em = $ctx->em;
        $coreEntity = $this->pixieService->getCore($core, $ctx->ownerRef);

        // Get a sample row
        $sampleRow = $em->getRepository(Row::class)->findOneBy(
            ['core' => $coreEntity],
            ['idWithinCore' => 'ASC']
        );

        $availableTemplates = $this->getAvailableTemplates($pixieCode, $core);

        return $this->render('@SurvosPixie/printer/preview.html.twig', [
            'pixieCode' => $pixieCode,
            'core' => $core,
            'sampleRow' => $sampleRow,
            'availableTemplates' => $availableTemplates,
            'owner' => $ctx->owner ?? null,
        ]);
    }

    private function resolveTemplateName(string $pixieCode, string $core, string $template): string
    {
        // Template priority:
        // 1. Project-specific: templates/pixie/print/{pixieCode}/{core}/{template}.html.twig
        // 2. Core-specific: templates/pixie/print/{core}/{template}.html.twig
        // 3. Generic: templates/pixie/print/{template}.html.twig
        // 4. Bundle default: @SurvosPixie/printer/{template}.html.twig

        $templatePaths = [
            "pixie/print/{$pixieCode}/{$core}/{$template}.html.twig",
            "pixie/print/{$pixieCode}/{$template}.html.twig",
            "pixie/print/{$core}/{$template}.html.twig",
            "pixie/print/{$template}.html.twig",
            "@SurvosPixie/printer/{$template}.html.twig",
            "@SurvosPixie/printer/default.html.twig", // fallback
        ];

        foreach ($templatePaths as $templatePath) {
            if ($this->twig->getLoader()->exists($templatePath)) {
                return $templatePath;
            }
        }

        return '@SurvosPixie/printer/default.html.twig';
    }

    private function getAvailableTemplates(string $pixieCode, string $core): array
    {
        // Scan for available templates
        $templates = ['default', 'compact', 'detailed', 'museum-label'];

        // TODO: Scan actual template directories for custom templates
        // This would require filesystem access to scan template directories

        return $templates;
    }

    private function renderPdf(string $templateName, array $context): Response
    {
        // For PDF generation, you'd typically use a service like:
        // - KnpSnappyBundle (wkhtmltopdf)
        // - DomPDF
        // - TCPDF
        //
        // For now, return HTML with print styles

        $html = $this->renderView($templateName, array_merge($context, [
            'format' => 'pdf',
            'isPdf' => true,
        ]));

        $response = new Response($html);
        $response->headers->set('Content-Type', 'text/html');

        return $response;
    }
}