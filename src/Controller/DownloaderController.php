<?php
// src/Controller/DownloaderController.php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Annotation\Route;

class DownloaderController extends AbstractController
{
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    #[Route('/downloader', name: 'app_downloader')]
    public function index(): Response
    {
        return $this->render('downloader/index.html.twig', [
            'controller_name' => 'DownloaderController',
        ]);
    }

    #[Route('/downloader/get-video-info', name: 'get_video_info', methods: ['POST'])]
    public function getVideoInfo(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $url = $data['url'] ?? '';

        if (!$url) {
            return new JsonResponse(['success' => false, 'message' => 'URL invalide.']);
        }

        // Obtenir le chemin absolu du répertoire du projet
        $projectDir = $this->getParameter('kernel.project_dir');

        // Chemin vers l'exécutable Python intégré
        $pythonPath = $projectDir . '/python/Python313/python.exe';

        // Chemin vers le script Python
        $scriptPath = $projectDir . '/templates/downloader/scripts/downloader.py';

        // Vérifier que l'exécutable Python existe
        if (!file_exists($pythonPath)) {
            $this->logger->error('Python executable not found at: ' . $pythonPath);
            return new JsonResponse([
                'success' => false,
                'message' => 'Python executable not found.',
            ]);
        }

        // Vérifier que le script Python existe
        if (!file_exists($scriptPath)) {
            $this->logger->error('Python script not found at: ' . $scriptPath);
            return new JsonResponse([
                'success' => false,
                'message' => 'Python script not found.',
            ]);
        }

        $process = new Process([$pythonPath, $scriptPath, $url]);
        $process->run();

        // Récupérer les sorties pour le débogage
        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();

        $this->logger->info('Python path: ' . $pythonPath);
        $this->logger->info('Script path: ' . $scriptPath);
        $this->logger->info('Process output: ' . $output);
        $this->logger->error('Process error output: ' . $errorOutput);

        if (!$process->isSuccessful()) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur lors de l\'exécution du script.',
                'error' => $errorOutput,
            ]);
        }

        $videoInfo = json_decode($output, true);

        if (!$videoInfo) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Impossible de décoder la sortie du script.',
                'error' => $output,
            ]);
        }

        // Construire l'URL publique de la miniature
        $thumbnailFilename = $videoInfo['thumbnail'];
        $thumbnailUrl = $thumbnailFilename ? $request->getBasePath() . '/images/cache/' . $thumbnailFilename : null;

        return new JsonResponse([
            'success' => true,
            'thumbnail' => $thumbnailUrl,
            'title' => $videoInfo['title']
        ]);
    }

    #[Route('/downloader/download-video', name: 'download_video')]
    public function downloadVideo(Request $request): Response
    {
        $url = $request->query->get('url');

        if (!$url) {
            return new Response('URL invalide.', 400);
        }

        // Définir un chemin de fichier temporaire
        $tempDir = sys_get_temp_dir();
        $filename = uniqid('video_') . '.mp4';
        $tempFile = $tempDir . DIRECTORY_SEPARATOR . $filename;

        // Obtenir le chemin absolu du répertoire du projet
        $projectDir = $this->getParameter('kernel.project_dir');

        // Chemin vers l'exécutable Python intégré
        $pythonPath = $projectDir . '/python/Python313/python.exe';

        // Chemin vers le script Python
        $scriptPath = $projectDir . '/templates/downloader/scripts/downloader.py';

        // Vérifier que l'exécutable Python existe
        if (!file_exists($pythonPath)) {
            $this->logger->error('Python executable not found at: ' . $pythonPath);
            return new Response('Python executable not found.', 500);
        }

        // Vérifier que le script Python existe
        if (!file_exists($scriptPath)) {
            $this->logger->error('Python script not found at: ' . $scriptPath);
            return new Response('Python script not found.', 500);
        }

        $process = new Process([$pythonPath, $scriptPath, '--download', $url, $tempFile]);
        $process->run();

        if (!$process->isSuccessful() || !file_exists($tempFile)) {
            $errorOutput = $process->getErrorOutput();
            return new Response('Erreur lors du téléchargement de la vidéo : ' . $errorOutput, 500);
        }

        // Retourner le fichier en réponse pour le téléchargement
        return $this->file($tempFile, basename($tempFile), ResponseHeaderBag::DISPOSITION_ATTACHMENT)->deleteFileAfterSend(true);
    }
}
