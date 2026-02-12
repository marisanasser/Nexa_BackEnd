<?php

declare(strict_types=1);

namespace App\Http\Controllers\Profile;

use Exception;
use Illuminate\Support\Facades\Log;

use App\Domain\Shared\Traits\HasApiResponses;
use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Domain\User\Services\PortfolioService;
use App\Http\Controllers\Base\Controller;
use App\Http\Requests\Profile\StorePortfolioItemRequest;
use App\Http\Requests\Profile\UpdatePortfolioItemRequest;
use App\Models\User\PortfolioItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\File;

class PortfolioController extends Controller
{
    use HasApiResponses;
    use HasAuthenticatedUser;

    public function __construct(
        private readonly PortfolioService $portfolioService
    ) {}

    /**
     * Get user's portfolio.
     */
    public function show(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (!$user->isCreator() && !$user->isStudent()) {
            return $this->errorResponse('Only creators and students can have portfolios', 403);
        }

        $portfolio = $this->portfolioService->getPortfolio($user);
        $items = $this->portfolioService->getItems($user);
        $stats = $this->portfolioService->getStatistics($user);

        // Format legacy response structure for compatibility if needed,
        // or return clean DTO. Sticking to a clean structure but compatible with what frontend might expect
        // based on previous controller analysis.

        $data = [
            'portfolio' => [
                'id' => $portfolio->id,
                'user_id' => $portfolio->user_id,
                'title' => $portfolio->title,
                'bio' => $portfolio->bio,
                'profile_picture' => $portfolio->profile_picture,
                'project_links' => $portfolio->project_links ?? [],
                'items' => $items,
            ],
            'stats' => $stats,
            'is_complete' => !empty($portfolio->title) && !empty($portfolio->bio) && $items->count() >= 3,
        ];

        return $this->successResponse($data, 'Portfolio retrieved successfully');
    }

    /**
     * Update portfolio details (bio, title, picture).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (!$user->isCreator() && !$user->isStudent()) {
            return $this->errorResponse('Permission denied', 403);
        }

        $request->validate([
            'title' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:1000',
            'whatsapp' => 'nullable|string|max:20',
            'profile_picture' => 'nullable|image|max:5120',
            'project_links' => 'nullable', // JSON or array handling inside service/controller logic if complex
        ]);

        $data = $request->only(['title', 'bio', 'whatsapp', 'project_links']);

        // Handle project_links JSON decoding if string
        if (isset($data['project_links']) && is_string($data['project_links'])) {
            $data['project_links'] = json_decode($data['project_links'], true) ?? [];
        }

        try {
            $portfolio = $this->portfolioService->updatePortfolioDetails(
                $user,
                $data,
                $request->file('profile_picture')
            );

            return $this->successResponse([
                'portfolio' => $portfolio,
                'user' => $user->fresh(),
            ], 'Portfolio updated successfully');
        } catch (Exception $e) {
            Log::error('Portfolio update failed', ['error' => $e->getMessage()]);

            return $this->errorResponse('Failed to update portfolio', 500);
        }
    }

    /**
     * Add items to portfolio.
     * Use batch upload logic to match previous controller's capability.
     */
    public function uploadMedia(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (!$user->isCreator() && !$user->isStudent()) {
            return $this->errorResponse('Permission denied', 403);
        }

        $files = $request->file('files');
        if (!$files) {
            $files = $request->file('file') ? [$request->file('file')] : [];
        }

        if (!is_array($files)) {
            $files = [$files];
        }

        if (empty($files)) {
            return $this->errorResponse('No files provided', 422);
        }

        if (count($files) > 10) {
            return $this->errorResponse('Too many files. Max 10 per request.', 422);
        }

        $createdItems = [];
        $errors = [];

        foreach ($files as $index => $file) {
            try {
                // Validate file type and size
                $validator = Validator::make(['file' => $file], [
                    'file' => [
                        'required',
                        'file',
                        'mimes:jpeg,png,jpg,webp,avif,gif,bmp,svg,mp4,mov,avi,webm,ogg,mkv,flv,3gp,wmv',
                        'max:102400' // 100MB
                    ]
                ], [
                    'file.mimes' => 'Tipo de arquivo não suportado. Use imagens ou vídeos (mp4, mov, avi, etc).',
                    'file.max' => 'O arquivo excede o limite de 100MB.'
                ]);

                if ($validator->fails()) {
                    $errors[] = "Arquivo {$file->getClientOriginalName()}: " . $validator->errors()->first('file');
                    continue;
                }

                // Determine type based on mime
                $mime = $file->getMimeType();
                $type = str_starts_with($mime, 'video') ? 'video' : 'image';

                $item = $this->portfolioService->addItem($user, [
                    'title' => $file->getClientOriginalName(),
                    'type' => $type,
                    'platform' => 'upload',
                ], $file);

                $createdItems[] = $item;
            } catch (Exception $e) {
                $errors[] = "File {$index}: " . $e->getMessage();
            }
        }

        if (empty($createdItems) && !empty($errors)) {
            return $this->errorResponse('Failed to upload files', 422, ['errors' => $errors]);
        }

        return $this->successResponse([
            'items' => $createdItems,
            'errors' => $errors,
        ], 'Media uploaded successfully');
    }

    /**
     * Store single item with metadata (preferred REST method).
     */
    public function store(StorePortfolioItemRequest $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        try {
            $item = $this->portfolioService->addItem(
                $user,
                $request->validated(),
                $request->file('file'),
                $request->file('thumbnail')
            );

            return $this->successResponse($item, 'Item added successfully', 201);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to add item: '.$e->getMessage(), 500);
        }
    }

    /**
     * Update an item.
     */
    public function update(UpdatePortfolioItemRequest $request, int $id): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $item = PortfolioItem::where('id', $id)
            ->whereHas('portfolio', function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->first();

        if (!$item) {
            return $this->notFoundResponse('Item not found or access denied');
        }

        try {
            $updatedItem = $this->portfolioService->updateItem(
                $item,
                $request->validated(),
                $request->file('file'),
                $request->file('thumbnail')
            );

            return $this->successResponse($updatedItem, 'Item updated successfully');
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update item', 500);
        }
    }

    /**
     * Delete an item.
     */
    public function destroy(int $id): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $item = PortfolioItem::where('id', $id)
            ->whereHas('portfolio', function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->first();

        if (!$item) {
            return $this->notFoundResponse('Item not found');
        }

        try {
            $this->portfolioService->deleteItem($item);

            return $this->successResponse(null, 'Item deleted successfully');
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete item', 500);
        }
    }

    /**
     * Reorder items.
     */
    public function reorder(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $request->validate(['order' => 'required|array']);

        try {
            $this->portfolioService->reorder($user, $request->input('order'));

            return $this->successResponse(null, 'Items reordered successfully');
        } catch (Exception $e) {
            return $this->errorResponse('Failed to reorder items', 500);
        }
    }

    /**
     * Get portfolio statistics.
     */
    public function statistics(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $stats = $this->portfolioService->getStatistics($user);

        return $this->successResponse($stats, 'Statistics retrieved successfully');
    }
}
