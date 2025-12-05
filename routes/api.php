<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Campaign\CampaignController;
use App\Http\Controllers\Campaign\BidController;
use App\Http\Controllers\CampaignApplicationController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\BrandProfileController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StripeBillingController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\CreatorBalanceController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Controllers\PostContractWorkflowController;
use App\Http\Controllers\AdminPayoutController;
use App\Http\Controllers\BrandPaymentController;
use App\Http\Controllers\CampaignTimelineController;
use App\Http\Controllers\ContractPaymentController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\DeliveryMaterialController;
use App\Http\Controllers\Admin\BrandRankingController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\StripeWebhookController;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Nexa API is running',
        'timestamp' => now()->toISOString()
    ]);
});

require __DIR__.'/auth.php';

Route::get('/download/{path}', function ($path) {
    $filePath = storage_path('app/public/' . $path);
    
    if (!file_exists($filePath)) {
        return response()->json(['error' => 'File not found'], 404);
    }
    
    $file = new \Illuminate\Http\File($filePath);
    $mimeType = $file->getMimeType();
    $fileName = basename($path);
    
    return response()->file($filePath, [
        'Content-Type' => $mimeType,
        'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin',
        'Access-Control-Allow-Credentials' => 'true',
    ]);
})->where('path', '.*');

Route::get('/guides', [GuideController::class, 'index']);                 
Route::get('/guides/{guide}', [GuideController::class, 'show']);          

Route::middleware(['auth:sanctum', 'user.status', 'throttle:user-status'])->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware(['auth:sanctum', 'user.status'])->prefix('student')->group(function () {
    Route::post('/verify', [StudentController::class, 'verifyStudent']);
    Route::get('/status', [StudentController::class, 'getStudentStatus']);
});

Route::middleware(['auth:sanctum', 'user.status'])->group(function () {
    
    
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->middleware(['throttle:dashboard']); 
        Route::put('/', [ProfileController::class, 'update']); 
        Route::post('/avatar', [ProfileController::class, 'uploadAvatar']); 
        Route::post('/avatar-base64', [ProfileController::class, 'uploadAvatarBase64']); 
        Route::delete('/avatar', [ProfileController::class, 'deleteAvatar']); 
    });
    
    
    Route::prefix('brand-profile')->group(function () {
        Route::get('/', [BrandProfileController::class, 'show'])->middleware(['throttle:dashboard']); 
        Route::put('/', [BrandProfileController::class, 'update']); 
        Route::post('/change-password', [BrandProfileController::class, 'changePassword']); 
        Route::post('/avatar', [BrandProfileController::class, 'uploadAvatar']); 
        Route::delete('/avatar', [BrandProfileController::class, 'deleteAvatar']); 
    });

    
    Route::prefix('campaigns')->middleware(['premium.access'])->group(function () {
        Route::get('/', [CampaignController::class, 'index'])->middleware(['throttle:dashboard']); 
        Route::get('/get-campaigns', [CampaignController::class, 'getCampaigns'])->middleware(['throttle:dashboard']); 
        Route::get('/get-all-campaigns', [CampaignController::class, 'getAllCampaigns'])->middleware(['throttle:dashboard']); 
        Route::get('/pending', [CampaignController::class, 'getPendingCampaigns'])->middleware(['throttle:dashboard']); 
        Route::get('/user/{user}', [CampaignController::class, 'getUserCampaigns'])->where('user', '[0-9]+'); 
        Route::get('/status/{status}', [CampaignController::class, 'getCampaignsByStatus'])->middleware(['throttle:dashboard'])->where('status', '[a-zA-Z]+'); 
        Route::post('/', [CampaignController::class, 'store']); 
        Route::get('/statistics', [CampaignController::class, 'statistics']); 
        Route::get('/favorites', [CampaignController::class, 'getFavorites'])->middleware(['throttle:dashboard']); 
        
        
        Route::patch('/{campaign}/approve', [CampaignController::class, 'approve']); 
        Route::patch('/{campaign}/reject', [CampaignController::class, 'reject']); 
        Route::patch('/{campaign}/archive', [CampaignController::class, 'archive']); 
        Route::patch('/{campaign}/toggle-featured', [CampaignController::class, 'toggleFeatured']); 
        Route::post('/{campaign}/toggle-active', [CampaignController::class, 'toggleActive']); 
        Route::post('/{campaign}/toggle-favorite', [CampaignController::class, 'toggleFavorite']); 
        Route::get('/{campaign}/bids', [BidController::class, 'campaignBids'])->where('campaign', '[0-9]+'); 
        
        
        Route::get('/{campaign}', [CampaignController::class, 'show'])->where('campaign', '[0-9]+'); 
        Route::patch('/{campaign}', [CampaignController::class, 'update'])->where('campaign', '[0-9]+'); 
        Route::delete('/{campaign}', [CampaignController::class, 'destroy'])->where('campaign', '[0-9]+'); 
    });
    
    
    Route::prefix('bids')->middleware(['premium.access'])->group(function () {
        Route::get('/', [BidController::class, 'index'])->middleware(['throttle:dashboard']); 
        Route::get('/{bid}', [BidController::class, 'show'])->where('bid', '[0-9]+'); 
        Route::put('/{bid}', [BidController::class, 'update'])->where('bid', '[0-9]+'); 
        Route::delete('/{bid}', [BidController::class, 'destroy'])->where('bid', '[0-9]+'); 
        
        
        Route::post('/{bid}/accept', [BidController::class, 'accept'])->where('bid', '[0-9]+'); 
        Route::post('/{bid}/reject', [BidController::class, 'reject'])->where('bid', '[0-9]+'); 
        
        
        Route::post('/{bid}/withdraw', [BidController::class, 'withdraw'])->where('bid', '[0-9]+'); 
    });
    
    
    Route::post('/campaigns/{campaign}/bids', [BidController::class, 'store'])->middleware(['premium.access'])->where('campaign', '[0-9]+'); 
    
    
    Route::prefix('applications')->middleware(['premium.access'])->group(function () {
        Route::get('/', [CampaignApplicationController::class, 'index'])->middleware(['throttle:dashboard']); 
        Route::get('/statistics', [CampaignApplicationController::class, 'statistics']); 
        Route::get('/{application}', [CampaignApplicationController::class, 'show'])->where('application', '[0-9]+'); 
        Route::post('/{application}/approve', [CampaignApplicationController::class, 'approve'])->where('application', '[0-9]+'); 
        Route::post('/{application}/reject', [CampaignApplicationController::class, 'reject'])->where('application', '[0-9]+'); 
        Route::delete('/{application}/withdraw', [CampaignApplicationController::class, 'withdraw'])->where('application', '[0-9]+'); 
    });
    
    
    Route::post('/campaigns/{campaign}/applications', [CampaignApplicationController::class, 'store'])->middleware(['premium.access'])->where('campaign', '[0-9]+'); 
    
    
    Route::get('/campaigns/{campaign}/applications', [CampaignApplicationController::class, 'campaignApplications'])->middleware(['premium.access'])->where('campaign', '[0-9]+'); 
    
    
    Route::prefix('chat')->group(function () {
        Route::get('/rooms', [ChatController::class, 'getChatRooms'])->middleware(['throttle:chat']); 
        Route::get('/rooms/{roomId}/messages', [ChatController::class, 'getMessages']); 
        Route::post('/rooms', [ChatController::class, 'createChatRoom']); 
        Route::post('/messages', [ChatController::class, 'sendMessage']); 
        Route::post('/mark-read', [ChatController::class, 'markMessagesAsRead']); 
        Route::post('/typing-status', [ChatController::class, 'updateTypingStatus']); 
        Route::post('/rooms/{roomId}/send-guide-messages', [ChatController::class, 'sendGuideMessages']); 
    });
    
    
    Route::prefix('connections')->middleware(['premium.access'])->group(function () {
        Route::post('/send-request', [ConnectionController::class, 'sendConnectionRequest']); 
        Route::post('/{requestId}/accept', [ConnectionController::class, 'acceptConnectionRequest'])->where('requestId', '[0-9]+'); 
        Route::post('/{requestId}/reject', [ConnectionController::class, 'rejectConnectionRequest'])->where('requestId', '[0-9]+'); 
        Route::post('/{requestId}/cancel', [ConnectionController::class, 'cancelConnectionRequest'])->where('requestId', '[0-9]+'); 
        Route::get('/requests', [ConnectionController::class, 'getConnectionRequests'])->middleware(['throttle:dashboard']); 
        Route::get('/search-creators', [ConnectionController::class, 'searchCreators'])->middleware(['throttle:dashboard']); 
    });
    
    
    Route::prefix('direct-chat')->middleware(['premium.access'])->group(function () {
        Route::get('/rooms', [ConnectionController::class, 'getDirectChatRooms'])->middleware(['throttle:dashboard']); 
        Route::get('/rooms/{roomId}/messages', [ConnectionController::class, 'getDirectMessages']); 
        Route::post('/messages', [ConnectionController::class, 'sendDirectMessage']); 
    });
});

Route::middleware(['auth:sanctum', 'throttle:notifications'])->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/mark-read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::get('/notifications/statistics', [NotificationController::class, 'statistics']);
    
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/account/remove', [AccountController::class, 'removeAccount']);
});

Route::group([],function () {
    Route::post('/account/restore', [AccountController::class, 'restoreAccount']);
    Route::post('/account/check-removed', [AccountController::class, 'checkRemovedAccount']);
});

Route::middleware(['auth:sanctum', 'user.status'])->group(function () {
    Route::get('/portfolio', [PortfolioController::class, 'show'])->middleware(['throttle:dashboard']);
    Route::post('/portfolio/profile', [PortfolioController::class, 'updateProfile']);
    Route::post('/portfolio/media', [PortfolioController::class, 'uploadMedia']);
    Route::post('/portfolio/test-upload', [PortfolioController::class, 'testUpload']); 
    Route::post('/portfolio/test-update', [PortfolioController::class, 'testUpdate']); 
    Route::put('/portfolio/items/{item}', [PortfolioController::class, 'updateItem']);
    Route::delete('/portfolio/items/{item}', [PortfolioController::class, 'deleteItem']);
    Route::post('/portfolio/reorder', [PortfolioController::class, 'reorderItems']);
    Route::get('/portfolio/statistics', [PortfolioController::class, 'statistics'])->middleware(['throttle:dashboard']);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/creators/{creatorId}/profile', [PortfolioController::class, 'getCreatorProfile'])->where('creatorId', '[0-9]+');
});

Route::get('/subscription/plans', [SubscriptionController::class, 'getPlans']);

Route::post('/payment/create-subscription-from-checkout-public', [StripeBillingController::class, 'createSubscriptionFromCheckoutPublic'])->middleware(['throttle:payment']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/payment/methods', [PaymentController::class, 'getPaymentMethods'])->middleware(['throttle:dashboard']);
    Route::post('/payment/methods', [PaymentController::class, 'createPaymentMethod']);
    Route::delete('/payment/methods/{cardId}', [PaymentController::class, 'deletePaymentMethod']);
    Route::post('/payment/process', [PaymentController::class, 'processPayment']);
    Route::get('/payment/history', [PaymentController::class, 'getPaymentHistory'])->middleware(['throttle:dashboard']);
    
    
    Route::middleware(['throttle:payment'])->group(function () {
        Route::post('/payment/subscription', [StripeBillingController::class, 'createSubscription']);
        Route::get('/payment/subscription-status', [StripeBillingController::class, 'getSubscriptionStatus']);
        Route::get('/payment/checkout-url', [StripeBillingController::class, 'getCheckoutUrl']);
        Route::post('/payment/create-subscription-from-checkout', [StripeBillingController::class, 'createSubscriptionFromCheckout']);
    });
    
    
    Route::post('/payment/debug', [PaymentController::class, 'debugPayment']);
    Route::post('/payment/test', function(Request $request) {
        return response()->json([
            'success' => true,
            'message' => 'Test endpoint working',
            'data' => $request->all(),
            'headers' => $request->headers->all(),
            'auth' => auth()->check(),
            'user' => auth()->user()
        ]);
    });
    
    
    Route::get('/subscription/history', [SubscriptionController::class, 'getSubscriptionHistory'])->middleware(['throttle:dashboard']);
    Route::post('/subscription/cancel', [SubscriptionController::class, 'cancelSubscription']);
    
    
    Route::get('/payment/transactions', [ContractPaymentController::class, 'getTransactionHistory'])->middleware(['throttle:dashboard']);
    
    
    Route::get('/brand/transactions', [ContractPaymentController::class, 'getBrandTransactionHistory'])->middleware(['throttle:dashboard']);
    
    
    Route::prefix('freelancer')->group(function () {
        
        Route::post('/register-bank', [PaymentController::class, 'registerBankAccount']); 
        Route::get('/bank-info', [PaymentController::class, 'getBankInfo']); 
        Route::put('/bank-info', [PaymentController::class, 'updateBankInfo']); 
        Route::delete('/bank-info', [PaymentController::class, 'deleteBankInfo']); 
        
        
        Route::get('/withdrawals', [WithdrawalController::class, 'index']); 
        Route::post('/withdrawals', [WithdrawalController::class, 'store']); 
        
        
        Route::get('/earnings', [PaymentController::class, 'getEarnings']); 
        Route::get('/withdrawal-methods', [CreatorBalanceController::class, 'withdrawalMethods']); 
        Route::post('/stripe-payment-method-checkout', [CreatorBalanceController::class, 'createStripePaymentMethodCheckout']); 
        Route::post('/stripe-payment-method-checkout-success', [CreatorBalanceController::class, 'handleCheckoutSuccess']); 
    });

    
    Route::prefix('brand-payment')->group(function () {
        Route::post('/save-method', [BrandPaymentController::class, 'savePaymentMethod']);
        Route::get('/methods', [BrandPaymentController::class, 'getPaymentMethods']);
        Route::post('/set-default', [BrandPaymentController::class, 'setDefaultPaymentMethod']);
        Route::delete('/methods', [BrandPaymentController::class, 'deletePaymentMethod']);
        Route::post('/create-checkout-session', [BrandPaymentController::class, 'createCheckoutSession']);
        Route::post('/create-funding-checkout', [BrandPaymentController::class, 'createFundingCheckout']);
        Route::post('/handle-checkout-success', [BrandPaymentController::class, 'handleCheckoutSuccess']);
        Route::post('/handle-offer-funding-success', [BrandPaymentController::class, 'handleOfferFundingSuccess']);
        Route::get('/check-funding-status', [BrandPaymentController::class, 'checkFundingStatus']); 
    });

    
    Route::prefix('stripe')->group(function () {
        Route::post('/connect/create-or-link', [StripeController::class, 'createAccount']);
        Route::post('/connect/account-link', [StripeController::class, 'createAccountLink']);
        Route::get('/connect/status', [StripeController::class, 'getAccountStatus']);
        Route::post('/setup-intent', [StripeController::class, 'setupIntent']);
        Route::get('/check', [StripeController::class, 'checkConfiguration']);
    });

    
    Route::prefix('contract-payment')->group(function () {
        Route::post('/process', [ContractPaymentController::class, 'processContractPayment']);
        Route::get('/status', [ContractPaymentController::class, 'getContractPaymentStatus']);
        Route::get('/methods', [ContractPaymentController::class, 'getAvailablePaymentMethods']);
        Route::post('/retry', [ContractPaymentController::class, 'retryPayment']);
        Route::post('/checkout-session', [ContractPaymentController::class, 'createContractCheckoutSession']);
    });
    
    
    Route::prefix('offers')->group(function () {
        Route::post('/', [OfferController::class, 'store']); 
        Route::post('/initial', [OfferController::class, 'sendInitialOffer']); 
        Route::post('/new-partnership', [OfferController::class, 'sendNewPartnershipOffer']); 
        Route::post('/renewal', [OfferController::class, 'sendRenewalOffer']); 
        Route::get('/', [OfferController::class, 'index']); 
        Route::get('/{id}', [OfferController::class, 'show'])->where('id', '[0-9]+'); 
        Route::post('/{id}/accept', [OfferController::class, 'accept'])->where('id', '[0-9]+'); 
        Route::post('/{id}/reject', [OfferController::class, 'reject'])->where('id', '[0-9]+'); 
        Route::delete('/{id}', [OfferController::class, 'cancel'])->where('id', '[0-9]+'); 
        Route::get('/chat-room/{roomId}', [OfferController::class, 'getOffersForChatRoom']); 
    });
    
    
    Route::prefix('contracts')->group(function () {
        Route::get('/', [ContractController::class, 'index']); 
        Route::get('/{id}', [ContractController::class, 'show'])->where('id', '[0-9]+'); 
        Route::get('/chat-room/{roomId}', [ContractController::class, 'getContractsForChatRoom']); 
        Route::post('/{id}/activate', [ContractController::class, 'activate'])->where('id', '[0-9]+'); 
        Route::post('/{id}/complete', [ContractController::class, 'complete'])->where('id', '[0-9]+'); 
        Route::post('/{id}/cancel', [ContractController::class, 'cancel'])->where('id', '[0-9]+'); 
        Route::post('/{id}/terminate', [ContractController::class, 'terminate'])->where('id', '[0-9]+'); 
        Route::post('/{id}/dispute', [ContractController::class, 'dispute'])->where('id', '[0-9]+'); 
    });
    
    
    Route::prefix('campaign-timeline')->group(function () {
        Route::get('/', [CampaignTimelineController::class, 'index'])->middleware(['throttle:dashboard']); 
        Route::post('/create-milestones', [CampaignTimelineController::class, 'createMilestones']); 
        Route::post('/upload-file', [CampaignTimelineController::class, 'uploadFile']); 
        Route::post('/approve-milestone', [CampaignTimelineController::class, 'approveMilestone']); 
        Route::post('/reject-milestone', [CampaignTimelineController::class, 'rejectMilestone']); 
        Route::post('/complete-milestone', [CampaignTimelineController::class, 'completeMilestone']); 
        Route::post('/justify-delay', [CampaignTimelineController::class, 'justifyDelay']); 
        Route::post('/mark-delayed', [CampaignTimelineController::class, 'markAsDelayed']); 
        Route::post('/extend-timeline', [CampaignTimelineController::class, 'extendTimeline']); 
        Route::get('/download-file', [CampaignTimelineController::class, 'downloadFile']); 
        Route::get('/statistics', [CampaignTimelineController::class, 'getStatistics'])->middleware(['throttle:dashboard']); 
        Route::post('/check-delay-warnings', [CampaignTimelineController::class, 'checkAndSendDelayWarnings']); 
    });
    
    
    Route::prefix('delivery-materials')->group(function () {
        Route::get('/', [DeliveryMaterialController::class, 'index'])->middleware(['throttle:dashboard']); 
        Route::post('/', [DeliveryMaterialController::class, 'store']); 
        Route::post('/{material}/approve', [DeliveryMaterialController::class, 'approve'])->where('material', '[0-9]+'); 
        Route::post('/{material}/reject', [DeliveryMaterialController::class, 'reject'])->where('material', '[0-9]+'); 
        Route::get('/{material}/download', [DeliveryMaterialController::class, 'download'])->where('material', '[0-9]+'); 
        Route::get('/statistics', [DeliveryMaterialController::class, 'getStatistics'])->middleware(['throttle:dashboard']); 
    });
    
    
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/reviews', [ReviewController::class, 'store']);
        Route::get('/reviews', [ReviewController::class, 'index'])->middleware(['throttle:dashboard']);
        Route::get('/reviews/{id}', [ReviewController::class, 'show'])->where('id', '[0-9]+');
        Route::put('/reviews/{id}', [ReviewController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('/reviews/{id}', [ReviewController::class, 'destroy'])->where('id', '[0-9]+');
        Route::get('/contracts/{contractId}/review-status', [ReviewController::class, 'getContractReviewStatus'])->where('contractId', '[0-9]+');
    });
    
    
    Route::prefix('creator-balance')->group(function () {
        Route::get('/', [CreatorBalanceController::class, 'index'])->middleware(['throttle:dashboard']); 
        Route::get('/history', [CreatorBalanceController::class, 'history'])->middleware(['throttle:dashboard']); 
        Route::get('/withdrawal-methods', [CreatorBalanceController::class, 'withdrawalMethods'])->middleware(['throttle:dashboard']); 
        Route::get('/work-history', [CreatorBalanceController::class, 'workHistory'])->middleware(['throttle:dashboard']); 
    });
    
    
    Route::prefix('withdrawals')->group(function () {
        Route::post('/', [WithdrawalController::class, 'store']); 
        Route::get('/', [WithdrawalController::class, 'index'])->middleware(['throttle:dashboard']); 
        Route::get('/{id}', [WithdrawalController::class, 'show'])->where('id', '[0-9]+'); 
        Route::delete('/{id}', [WithdrawalController::class, 'cancel'])->where('id', '[0-9]+'); 
        Route::get('/statistics', [WithdrawalController::class, 'statistics'])->middleware(['throttle:dashboard']); 
    });
    
    
    Route::prefix('post-contract')->group(function () {
        Route::get('/waiting-review', [PostContractWorkflowController::class, 'getContractsWaitingForReview'])->middleware(['throttle:dashboard']); 
        Route::get('/payment-available', [PostContractWorkflowController::class, 'getContractsWithPaymentAvailable'])->middleware(['throttle:dashboard']); 
        Route::get('/work-history', [PostContractWorkflowController::class, 'getWorkHistory'])->middleware(['throttle:dashboard']); 
    });
    
    
    
    
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    
    Route::get('/dashboard-metrics', [AdminController::class, 'getDashboardMetrics']);
    Route::get('/pending-campaigns', [AdminController::class, 'getPendingCampaigns']);
    Route::get('/recent-users', [AdminController::class, 'getRecentUsers']);
    
    
    Route::get('/campaigns', [AdminController::class, 'getCampaigns']);
    Route::patch('/campaigns/{id}/approve', [AdminController::class, 'approveCampaign'])->where('id', '[0-9]+');
    Route::patch('/campaigns/{id}/reject', [AdminController::class, 'rejectCampaign'])->where('id', '[0-9]+');
    Route::get('/campaigns/{id}', [AdminController::class, 'getCampaign'])->where('id', '[0-9]+');
    Route::patch('/campaigns/{id}', [AdminController::class, 'updateCampaign'])->where('id', '[0-9]+');
    Route::delete('/campaigns/{id}', [AdminController::class, 'deleteCampaign'])->where('id', '[0-9]+');
    
    
    Route::get('/users', [AdminController::class, 'getUsers']);
    Route::get('/users/creators', [AdminController::class, 'getCreators']);
    Route::get('/users/brands', [AdminController::class, 'getBrands']);
    Route::get('/users/statistics', [AdminController::class, 'getUserStatistics']);
    Route::patch('/users/{user}/status', [AdminController::class, 'updateUserStatus'])->where('user', '[0-9]+');
    
    
    Route::get('/students', [AdminController::class, 'getStudents']);
    Route::patch('/students/{student}/trial', [AdminController::class, 'updateStudentTrial'])->where('student', '[0-9]+');
    Route::patch('/students/{student}/status', [AdminController::class, 'updateStudentStatus'])->where('student', '[0-9]+');

    
    Route::get('/student-requests', [AdminController::class, 'getStudentVerificationRequests']);
    Route::patch('/student-requests/{id}/approve', [AdminController::class, 'approveStudentVerification'])->where('id', '[0-9]+');
    Route::patch('/student-requests/{id}/reject', [AdminController::class, 'rejectStudentVerification'])->where('id', '[0-9]+');
    
    
    Route::apiResource('withdrawal-methods', \App\Http\Controllers\Admin\WithdrawalMethodController::class);
    Route::put('/withdrawal-methods/{id}/toggle-active', [\App\Http\Controllers\Admin\WithdrawalMethodController::class, 'toggleActive'])->where('id', '[0-9]+');
    
    
    Route::get('/payouts/pending', [AdminPayoutController::class, 'getPendingWithdrawals']);
    Route::post('/payouts/{id}/process', [AdminPayoutController::class, 'processWithdrawal'])->where('id', '[0-9]+');
    Route::get('/payouts/verification-report', [AdminPayoutController::class, 'getWithdrawalVerificationReport']);
    Route::get('/payouts/{id}/verify', [AdminPayoutController::class, 'verifyWithdrawal'])->where('id', '[0-9]+');

    
    Route::get('/guides', [AdminController::class, 'getGuides']);
    Route::get('/guides/{id}', [AdminController::class, 'getGuide'])->where('id', '[0-9]+');
    Route::post('/guides', [GuideController::class, 'store']);                
    Route::put('/guides/{id}', [AdminController::class, 'updateGuide'])->where('id', '[0-9]+');        
    Route::delete('/guides/{id}', function ($id) {
        $guide = \App\Models\Guide::findOrFail($id);
        return app(\App\Http\Controllers\GuideController::class)->destroy($guide);
    })->where('id', '[0-9]+');    
    
    
    Route::get('/brand-rankings', [BrandRankingController::class, 'getBrandRankings']);
    Route::get('/brand-rankings/comprehensive', [BrandRankingController::class, 'getComprehensiveRankings']);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/guides', [GuideController::class, 'index']);                 
    Route::get('/guides/{guide}', [GuideController::class, 'show'])->where('guide', '[0-9]+');          
});

Route::get('/google/redirect', [GoogleController::class, 'redirectToGoogle'])
    ->name('google.redirect');

Route::get('/google/callback', [GoogleController::class, 'handleGoogleCallback'])
    ->name('google.callback');

Route::post('/google/auth', [GoogleController::class, 'handleGoogleWithRole'])
    ->name('google.auth');

Route::post('/account/checked', [AccountController::class, 'checkAccount']);

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);

