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


// Health check endpoint
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Nexa API is running',
        'timestamp' => now()->toISOString()
    ]);
});

// Include auth routes FIRST to ensure they take priority
// Note: Auth routes have their own rate limiting middleware
require __DIR__.'/auth.php';

// File download route with CORS headers
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

// Public guide routes (read-only, no authentication required)
Route::get('/guides', [GuideController::class, 'index']);                 // Get all guides
Route::get('/guides/{guide}', [GuideController::class, 'show']);          // Get a single guide by ID

// User status check (requires authentication) - with specific rate limiting
Route::middleware(['auth:sanctum', 'user.status', 'throttle:user-status'])->get('/user', function (Request $request) {
    return $request->user();
});

// Student verification routes (requires authentication)
Route::middleware(['auth:sanctum', 'user.status'])->prefix('student')->group(function () {
    Route::post('/verify', [StudentController::class, 'verifyStudent']);
    Route::get('/status', [StudentController::class, 'getStudentStatus']);
});

// Authenticated user routes - with specific rate limiting per endpoint
Route::middleware(['auth:sanctum', 'user.status'])->group(function () {
    
    // Profile management (available to all authenticated users)
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->middleware(['throttle:dashboard']); // Get current user profile
        Route::put('/', [ProfileController::class, 'update']); // Update current user profile
        Route::post('/avatar', [ProfileController::class, 'uploadAvatar']); // Upload avatar (multipart)
        Route::post('/avatar-base64', [ProfileController::class, 'uploadAvatarBase64']); // Upload avatar via base64
        Route::delete('/avatar', [ProfileController::class, 'deleteAvatar']); // Delete avatar
    });
    
    // Brand Profile management
    Route::prefix('brand-profile')->group(function () {
        Route::get('/', [BrandProfileController::class, 'show'])->middleware(['throttle:dashboard']); // Get brand profile
        Route::put('/', [BrandProfileController::class, 'update']); // Update brand profile
        Route::post('/change-password', [BrandProfileController::class, 'changePassword']); // Change password
        Route::post('/avatar', [BrandProfileController::class, 'uploadAvatar']); // Upload avatar
        Route::delete('/avatar', [BrandProfileController::class, 'deleteAvatar']); // Delete avatar
    });

    // Campaign CRUD operations (require premium for creators)
    Route::prefix('campaigns')->middleware(['premium.access'])->group(function () {
        Route::get('/', [CampaignController::class, 'index'])->middleware(['throttle:dashboard']); // List campaigns
        Route::get('/get-campaigns', [CampaignController::class, 'getCampaigns'])->middleware(['throttle:dashboard']); // Get campaigns with advanced filtering
        Route::get('/get-all-campaigns', [CampaignController::class, 'getAllCampaigns'])->middleware(['throttle:dashboard']); // Get all campaigns without pagination
        Route::get('/pending', [CampaignController::class, 'getPendingCampaigns'])->middleware(['throttle:dashboard']); // Get pending campaigns
        Route::get('/user/{user}', [CampaignController::class, 'getUserCampaigns'])->where('user', '[0-9]+'); // Get campaigns by user
        Route::get('/status/{status}', [CampaignController::class, 'getCampaignsByStatus'])->middleware(['throttle:dashboard'])->where('status', '[a-zA-Z]+'); // Get campaigns by status
        Route::post('/', [CampaignController::class, 'store']); // Create campaign (Brand only)
        Route::get('/statistics', [CampaignController::class, 'statistics']); // Get statistics
        Route::get('/favorites', [CampaignController::class, 'getFavorites'])->middleware(['throttle:dashboard']); // Get favorite campaigns (Creator only)
        
        // Specific campaign operations (must come before {campaign} route)
        Route::patch('/{campaign}/approve', [CampaignController::class, 'approve']); // Approve campaign (Admin only)
        Route::patch('/{campaign}/reject', [CampaignController::class, 'reject']); // Reject campaign (Admin only)
        Route::patch('/{campaign}/archive', [CampaignController::class, 'archive']); // Archive campaign (Admin/Brand only)
        Route::patch('/{campaign}/toggle-featured', [CampaignController::class, 'toggleFeatured']); // Toggle featured status (Admin only)
        Route::post('/{campaign}/toggle-active', [CampaignController::class, 'toggleActive']); // Toggle active status (Brand only)
        Route::post('/{campaign}/toggle-favorite', [CampaignController::class, 'toggleFavorite']); // Toggle favorite status (Creator only)
        Route::get('/{campaign}/bids', [BidController::class, 'campaignBids'])->where('campaign', '[0-9]+'); // Get bids for campaign
        
        // Generic campaign routes (must come after specific routes)
        Route::get('/{campaign}', [CampaignController::class, 'show'])->where('campaign', '[0-9]+'); // View campaign
        Route::patch('/{campaign}', [CampaignController::class, 'update'])->where('campaign', '[0-9]+'); // Update campaign (Brand only)
        Route::delete('/{campaign}', [CampaignController::class, 'destroy'])->where('campaign', '[0-9]+'); // Delete campaign (Brand only)
    });
    
    // Bid CRUD operations (require premium for creators)
    Route::prefix('bids')->middleware(['premium.access'])->group(function () {
        Route::get('/', [BidController::class, 'index'])->middleware(['throttle:dashboard']); // List bids
        Route::get('/{bid}', [BidController::class, 'show'])->where('bid', '[0-9]+'); // View bid
        Route::put('/{bid}', [BidController::class, 'update'])->where('bid', '[0-9]+'); // Update bid (Creator only)
        Route::delete('/{bid}', [BidController::class, 'destroy'])->where('bid', '[0-9]+'); // Delete bid (Creator only)
        
        // Brand operations
        Route::post('/{bid}/accept', [BidController::class, 'accept'])->where('bid', '[0-9]+'); // Accept bid (Brand only)
        Route::post('/{bid}/reject', [BidController::class, 'reject'])->where('bid', '[0-9]+'); // Reject bid (Brand only)
        
        // Creator operations
        Route::post('/{bid}/withdraw', [BidController::class, 'withdraw'])->where('bid', '[0-9]+'); // Withdraw bid (Creator only)
    });
    
    // Create bid on campaign (require premium for creators)
    Route::post('/campaigns/{campaign}/bids', [BidController::class, 'store'])->middleware(['premium.access'])->where('campaign', '[0-9]+'); // Create bid on campaign (Creator only)
    
    // Campaign Application CRUD operations (require premium for creators)
    Route::prefix('applications')->middleware(['premium.access'])->group(function () {
        Route::get('/', [CampaignApplicationController::class, 'index'])->middleware(['throttle:dashboard']); // List applications (role-based)
        Route::get('/statistics', [CampaignApplicationController::class, 'statistics']); // Get application statistics
        Route::get('/{application}', [CampaignApplicationController::class, 'show'])->where('application', '[0-9]+'); // View application
        Route::post('/{application}/approve', [CampaignApplicationController::class, 'approve'])->where('application', '[0-9]+'); // Approve application (Brand only)
        Route::post('/{application}/reject', [CampaignApplicationController::class, 'reject'])->where('application', '[0-9]+'); // Reject application (Brand only)
        Route::delete('/{application}/withdraw', [CampaignApplicationController::class, 'withdraw'])->where('application', '[0-9]+'); // Withdraw application (Creator only)
    });
    
    // Create application on campaign (require premium for creators)
    Route::post('/campaigns/{campaign}/applications', [CampaignApplicationController::class, 'store'])->middleware(['premium.access'])->where('campaign', '[0-9]+'); // Create application (Creator only)
    
    // Get applications for a specific campaign (require premium for creators)
    Route::get('/campaigns/{campaign}/applications', [CampaignApplicationController::class, 'campaignApplications'])->middleware(['premium.access'])->where('campaign', '[0-9]+'); // Get campaign applications
    
    // Chat room management (available to all authenticated users)
    Route::prefix('chat')->group(function () {
        Route::get('/rooms', [ChatController::class, 'getChatRooms'])->middleware(['throttle:chat']); // Get user's chat rooms
        Route::get('/rooms/{roomId}/messages', [ChatController::class, 'getMessages']); // Get messages for a room
        Route::post('/rooms', [ChatController::class, 'createChatRoom']); // Create chat room (brand accepts proposal)
        Route::post('/messages', [ChatController::class, 'sendMessage']); // Send a message
        Route::post('/mark-read', [ChatController::class, 'markMessagesAsRead']); // Mark messages as read
        Route::post('/typing-status', [ChatController::class, 'updateTypingStatus']); // Update typing status
        Route::post('/rooms/{roomId}/send-guide-messages', [ChatController::class, 'sendGuideMessages']); // Send guide messages when user first enters chat
    });
    
    // Connection management (require premium for creators)
    Route::prefix('connections')->middleware(['premium.access'])->group(function () {
        Route::post('/send-request', [ConnectionController::class, 'sendConnectionRequest']); // Send connection request
        Route::post('/{requestId}/accept', [ConnectionController::class, 'acceptConnectionRequest'])->where('requestId', '[0-9]+'); // Accept connection request
        Route::post('/{requestId}/reject', [ConnectionController::class, 'rejectConnectionRequest'])->where('requestId', '[0-9]+'); // Reject connection request
        Route::post('/{requestId}/cancel', [ConnectionController::class, 'cancelConnectionRequest'])->where('requestId', '[0-9]+'); // Cancel connection request
        Route::get('/requests', [ConnectionController::class, 'getConnectionRequests'])->middleware(['throttle:dashboard']); // Get connection requests
        Route::get('/search-creators', [ConnectionController::class, 'searchCreators'])->middleware(['throttle:dashboard']); // Search for creators (brands only)
    });
    
    // Direct chat management (require premium for creators)
    Route::prefix('direct-chat')->middleware(['premium.access'])->group(function () {
        Route::get('/rooms', [ConnectionController::class, 'getDirectChatRooms'])->middleware(['throttle:dashboard']); // Get direct chat rooms
        Route::get('/rooms/{roomId}/messages', [ConnectionController::class, 'getDirectMessages']); // Get direct messages
        Route::post('/messages', [ConnectionController::class, 'sendDirectMessage']); // Send direct message
    });
});

// Notification routes (available to all authenticated users)
Route::middleware(['auth:sanctum', 'throttle:notifications'])->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/mark-read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::get('/notifications/statistics', [NotificationController::class, 'statistics']);
    
});

// Account management routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/account/remove', [AccountController::class, 'removeAccount']);
});

// Public account restoration routes (no authentication required)
Route::group([],function () {
    Route::post('/account/restore', [AccountController::class, 'restoreAccount']);
    Route::post('/account/check-removed', [AccountController::class, 'checkRemovedAccount']);
});

// Portfolio routes (available to all authenticated creators)
Route::middleware(['auth:sanctum', 'user.status'])->group(function () {
    Route::get('/portfolio', [PortfolioController::class, 'show'])->middleware(['throttle:dashboard']);
    Route::post('/portfolio/profile', [PortfolioController::class, 'updateProfile']);
    Route::post('/portfolio/media', [PortfolioController::class, 'uploadMedia']);
    Route::post('/portfolio/test-upload', [PortfolioController::class, 'testUpload']); // Test endpoint
    Route::post('/portfolio/test-update', [PortfolioController::class, 'testUpdate']); // Test update endpoint
    Route::put('/portfolio/items/{item}', [PortfolioController::class, 'updateItem']);
    Route::delete('/portfolio/items/{item}', [PortfolioController::class, 'deleteItem']);
    Route::post('/portfolio/reorder', [PortfolioController::class, 'reorderItems']);
    Route::get('/portfolio/statistics', [PortfolioController::class, 'statistics'])->middleware(['throttle:dashboard']);
});

// Creator profile for brands (public view)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/creators/{creatorId}/profile', [PortfolioController::class, 'getCreatorProfile'])->where('creatorId', '[0-9]+');
});

// Public subscription plans (no authentication required)
Route::get('/subscription/plans', [SubscriptionController::class, 'getPlans']);

// Payment routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/payment/methods', [PaymentController::class, 'getPaymentMethods'])->middleware(['throttle:dashboard']);
    Route::post('/payment/methods', [PaymentController::class, 'createPaymentMethod']);
    Route::delete('/payment/methods/{cardId}', [PaymentController::class, 'deletePaymentMethod']);
    Route::post('/payment/process', [PaymentController::class, 'processPayment']);
    Route::get('/payment/history', [PaymentController::class, 'getPaymentHistory'])->middleware(['throttle:dashboard']);
    
    // Subscription routes
    Route::middleware(['throttle:payment'])->group(function () {
        Route::post('/payment/subscription', [StripeBillingController::class, 'createSubscription']);
        Route::get('/payment/subscription-status', [StripeBillingController::class, 'getSubscriptionStatus']);
        Route::get('/payment/checkout-url', [StripeBillingController::class, 'getCheckoutUrl']);
        Route::post('/payment/create-subscription-from-checkout', [StripeBillingController::class, 'createSubscriptionFromCheckout']);
    });
    
    // Debug routes (temporary)
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
    
    // Subscription management routes
    Route::get('/subscription/history', [SubscriptionController::class, 'getSubscriptionHistory'])->middleware(['throttle:dashboard']);
    Route::post('/subscription/cancel', [SubscriptionController::class, 'cancelSubscription']);
    
    // Payment transaction history (requires authentication)
    Route::get('/payment/transactions', [ContractPaymentController::class, 'getTransactionHistory'])->middleware(['throttle:dashboard']);
    
    // Freelancer payment routes
    Route::prefix('freelancer')->group(function () {
        // Bank account management
        Route::post('/register-bank', [PaymentController::class, 'registerBankAccount']); // Register bank account
        Route::get('/bank-info', [PaymentController::class, 'getBankInfo']); // Get bank info
        Route::put('/bank-info', [PaymentController::class, 'updateBankInfo']); // Update bank info
        Route::delete('/bank-info', [PaymentController::class, 'deleteBankInfo']); // Delete bank info
        
        // Withdrawal management
        Route::get('/withdrawals', [WithdrawalController::class, 'index']); // Get withdrawal history
        Route::post('/withdrawals', [WithdrawalController::class, 'store']); // Request withdrawal
        
        // Earnings and balance
        Route::get('/earnings', [PaymentController::class, 'getEarnings']); // Get earnings and balance
        Route::get('/withdrawal-methods', [CreatorBalanceController::class, 'withdrawalMethods']); // Get available withdrawal methods
        Route::post('/stripe-payment-method-checkout', [CreatorBalanceController::class, 'createStripePaymentMethodCheckout']); // Connect Stripe payment method for withdrawals
    });

    // Brand payment methods (for contract payments)
    Route::prefix('brand-payment')->group(function () {
        Route::post('/save-method', [BrandPaymentController::class, 'savePaymentMethod']);
        Route::get('/methods', [BrandPaymentController::class, 'getPaymentMethods']);
        Route::post('/set-default', [BrandPaymentController::class, 'setDefaultPaymentMethod']);
        Route::delete('/methods', [BrandPaymentController::class, 'deletePaymentMethod']);
        Route::post('/create-checkout-session', [BrandPaymentController::class, 'createCheckoutSession']);
        Route::post('/create-funding-checkout', [BrandPaymentController::class, 'createFundingCheckout']);
        Route::post('/handle-checkout-success', [BrandPaymentController::class, 'handleCheckoutSuccess']);
    });

    // Stripe Connect and setup
    Route::prefix('stripe')->group(function () {
        Route::post('/connect/create-or-link', [StripeController::class, 'createAccount']);
        Route::post('/connect/account-link', [StripeController::class, 'createAccountLink']);
        Route::get('/connect/status', [StripeController::class, 'getAccountStatus']);
        Route::post('/setup-intent', [StripeController::class, 'setupIntent']);
        Route::get('/check', [StripeController::class, 'checkConfiguration']);
    });

    // Contract payment processing
    Route::prefix('contract-payment')->group(function () {
        Route::post('/process', [ContractPaymentController::class, 'processContractPayment']);
        Route::get('/status', [ContractPaymentController::class, 'getContractPaymentStatus']);
        Route::get('/methods', [ContractPaymentController::class, 'getAvailablePaymentMethods']);
        Route::post('/retry', [ContractPaymentController::class, 'retryPayment']);
        Route::post('/checkout-session', [ContractPaymentController::class, 'createContractCheckoutSession']);
    });
    
    // Offer routes
    Route::prefix('offers')->group(function () {
        Route::post('/', [OfferController::class, 'store']); // Create offer
        Route::post('/initial', [OfferController::class, 'sendInitialOffer']); // Send initial offer automatically
        Route::post('/new-partnership', [OfferController::class, 'sendNewPartnershipOffer']); // Send new partnership offer
        Route::post('/renewal', [OfferController::class, 'sendRenewalOffer']); // Send renewal offer after contract completion
        Route::get('/', [OfferController::class, 'index']); // Get offers
        Route::get('/{id}', [OfferController::class, 'show'])->where('id', '[0-9]+'); // Get specific offer
        Route::post('/{id}/accept', [OfferController::class, 'accept'])->where('id', '[0-9]+'); // Accept offer
        Route::post('/{id}/reject', [OfferController::class, 'reject'])->where('id', '[0-9]+'); // Reject offer
        Route::delete('/{id}', [OfferController::class, 'cancel'])->where('id', '[0-9]+'); // Cancel offer
        Route::get('/chat-room/{roomId}', [OfferController::class, 'getOffersForChatRoom']); // Get offers for chat room
    });
    
    // Contract routes
    Route::prefix('contracts')->group(function () {
        Route::get('/', [ContractController::class, 'index']); // Get contracts
        Route::get('/{id}', [ContractController::class, 'show'])->where('id', '[0-9]+'); // Get specific contract
        Route::get('/chat-room/{roomId}', [ContractController::class, 'getContractsForChatRoom']); // Get contracts for chat room
        Route::post('/{id}/activate', [ContractController::class, 'activate'])->where('id', '[0-9]+'); // Activate contract
        Route::post('/{id}/complete', [ContractController::class, 'complete'])->where('id', '[0-9]+'); // Complete contract
        Route::post('/{id}/cancel', [ContractController::class, 'cancel'])->where('id', '[0-9]+'); // Cancel contract
        Route::post('/{id}/terminate', [ContractController::class, 'terminate'])->where('id', '[0-9]+'); // Terminate contract (brand only)
        Route::post('/{id}/dispute', [ContractController::class, 'dispute'])->where('id', '[0-9]+'); // Dispute contract
    });
    
    // Campaign Timeline routes
    Route::prefix('campaign-timeline')->group(function () {
        Route::get('/', [CampaignTimelineController::class, 'index'])->middleware(['throttle:dashboard']); // Get timeline for contract
        Route::post('/create-milestones', [CampaignTimelineController::class, 'createMilestones']); // Create milestones for contract
        Route::post('/upload-file', [CampaignTimelineController::class, 'uploadFile']); // Upload file for milestone
        Route::post('/approve-milestone', [CampaignTimelineController::class, 'approveMilestone']); // Approve milestone
        Route::post('/reject-milestone', [CampaignTimelineController::class, 'rejectMilestone']); // Reject milestone
        Route::post('/complete-milestone', [CampaignTimelineController::class, 'completeMilestone']); // Complete milestone
        Route::post('/justify-delay', [CampaignTimelineController::class, 'justifyDelay']); // Justify delay
        Route::post('/mark-delayed', [CampaignTimelineController::class, 'markAsDelayed']); // Mark as delayed
        Route::post('/extend-timeline', [CampaignTimelineController::class, 'extendTimeline']); // Extend timeline
        Route::get('/download-file', [CampaignTimelineController::class, 'downloadFile']); // Download file
        Route::get('/statistics', [CampaignTimelineController::class, 'getStatistics'])->middleware(['throttle:dashboard']); // Get timeline statistics
        Route::post('/check-delay-warnings', [CampaignTimelineController::class, 'checkAndSendDelayWarnings']); // Check and send delay warnings
    });
    
    // Delivery Material routes
    Route::prefix('delivery-materials')->group(function () {
        Route::get('/', [DeliveryMaterialController::class, 'index'])->middleware(['throttle:dashboard']); // Get delivery materials for contract
        Route::post('/', [DeliveryMaterialController::class, 'store']); // Submit delivery material
        Route::post('/{material}/approve', [DeliveryMaterialController::class, 'approve'])->where('material', '[0-9]+'); // Approve delivery material
        Route::post('/{material}/reject', [DeliveryMaterialController::class, 'reject'])->where('material', '[0-9]+'); // Reject delivery material
        Route::get('/{material}/download', [DeliveryMaterialController::class, 'download'])->where('material', '[0-9]+'); // Download delivery material
        Route::get('/statistics', [DeliveryMaterialController::class, 'getStatistics'])->middleware(['throttle:dashboard']); // Get delivery material statistics
    });
    
    // Review routes
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/reviews', [ReviewController::class, 'store']);
        Route::get('/reviews', [ReviewController::class, 'index'])->middleware(['throttle:dashboard']);
        Route::get('/reviews/{id}', [ReviewController::class, 'show'])->where('id', '[0-9]+');
        Route::put('/reviews/{id}', [ReviewController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('/reviews/{id}', [ReviewController::class, 'destroy'])->where('id', '[0-9]+');
        Route::get('/contracts/{contractId}/review-status', [ReviewController::class, 'getContractReviewStatus'])->where('contractId', '[0-9]+');
    });
    
    // Creator balance routes
    Route::prefix('creator-balance')->group(function () {
        Route::get('/', [CreatorBalanceController::class, 'index'])->middleware(['throttle:dashboard']); // Get balance
        Route::get('/history', [CreatorBalanceController::class, 'history'])->middleware(['throttle:dashboard']); // Get balance history
        Route::get('/withdrawal-methods', [CreatorBalanceController::class, 'withdrawalMethods'])->middleware(['throttle:dashboard']); // Get withdrawal methods
        Route::get('/work-history', [CreatorBalanceController::class, 'workHistory'])->middleware(['throttle:dashboard']); // Get work history
    });
    
    // Withdrawal routes
    Route::prefix('withdrawals')->group(function () {
        Route::post('/', [WithdrawalController::class, 'store']); // Create withdrawal
        Route::get('/', [WithdrawalController::class, 'index'])->middleware(['throttle:dashboard']); // Get withdrawals
        Route::get('/{id}', [WithdrawalController::class, 'show'])->where('id', '[0-9]+'); // Get specific withdrawal
        Route::delete('/{id}', [WithdrawalController::class, 'cancel'])->where('id', '[0-9]+'); // Cancel withdrawal
        Route::get('/statistics', [WithdrawalController::class, 'statistics'])->middleware(['throttle:dashboard']); // Get withdrawal statistics
    });
    
    // Post-contract workflow routes
    Route::prefix('post-contract')->group(function () {
        Route::get('/waiting-review', [PostContractWorkflowController::class, 'getContractsWaitingForReview'])->middleware(['throttle:dashboard']); // Get contracts waiting for review
        Route::get('/payment-available', [PostContractWorkflowController::class, 'getContractsWithPaymentAvailable'])->middleware(['throttle:dashboard']); // Get contracts with payment available
        Route::get('/work-history', [PostContractWorkflowController::class, 'getWorkHistory'])->middleware(['throttle:dashboard']); // Get work history
    });
    
    // Public guide routes (read-only for authenticated users)
    // Route::get('/guides', [GuideController::class, 'index']);                 // Get all guides
    // Route::get('/guides/{guide}', [GuideController::class, 'show']);          // Get a single guide by ID
});

// Admin routes
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    // Dashboard endpoints
    Route::get('/dashboard-metrics', [AdminController::class, 'getDashboardMetrics']);
    Route::get('/pending-campaigns', [AdminController::class, 'getPendingCampaigns']);
    Route::get('/recent-users', [AdminController::class, 'getRecentUsers']);
    
    // Campaign management
    Route::get('/campaigns', [AdminController::class, 'getCampaigns']);
    Route::patch('/campaigns/{id}/approve', [AdminController::class, 'approveCampaign'])->where('id', '[0-9]+');
    Route::patch('/campaigns/{id}/reject', [AdminController::class, 'rejectCampaign'])->where('id', '[0-9]+');
    Route::get('/campaigns/{id}', [AdminController::class, 'getCampaign'])->where('id', '[0-9]+');
    Route::patch('/campaigns/{id}', [AdminController::class, 'updateCampaign'])->where('id', '[0-9]+');
    Route::delete('/campaigns/{id}', [AdminController::class, 'deleteCampaign'])->where('id', '[0-9]+');
    
    // User management
    Route::get('/users', [AdminController::class, 'getUsers']);
    Route::get('/users/creators', [AdminController::class, 'getCreators']);
    Route::get('/users/brands', [AdminController::class, 'getBrands']);
    Route::get('/users/statistics', [AdminController::class, 'getUserStatistics']);
    Route::patch('/users/{user}/status', [AdminController::class, 'updateUserStatus'])->where('user', '[0-9]+');
    
    // Student management
    Route::get('/students', [AdminController::class, 'getStudents']);
    Route::patch('/students/{student}/trial', [AdminController::class, 'updateStudentTrial'])->where('student', '[0-9]+');
    Route::patch('/students/{student}/status', [AdminController::class, 'updateStudentStatus'])->where('student', '[0-9]+');

    // Student verification requests
    Route::get('/student-requests', [AdminController::class, 'getStudentVerificationRequests']);
    Route::patch('/student-requests/{id}/approve', [AdminController::class, 'approveStudentVerification'])->where('id', '[0-9]+');
    Route::patch('/student-requests/{id}/reject', [AdminController::class, 'rejectStudentVerification'])->where('id', '[0-9]+');
    
    // Withdrawal methods management
    Route::apiResource('withdrawal-methods', \App\Http\Controllers\Admin\WithdrawalMethodController::class);
    Route::put('/withdrawal-methods/{id}/toggle-active', [\App\Http\Controllers\Admin\WithdrawalMethodController::class, 'toggleActive'])->where('id', '[0-9]+');
    
    // Payout management
    Route::get('/payouts/pending', [AdminPayoutController::class, 'getPendingWithdrawals']);
    Route::post('/payouts/{id}/process', [AdminPayoutController::class, 'processWithdrawal'])->where('id', '[0-9]+');
    Route::get('/payouts/verification-report', [AdminPayoutController::class, 'getWithdrawalVerificationReport']);
    Route::get('/payouts/{id}/verify', [AdminPayoutController::class, 'verifyWithdrawal'])->where('id', '[0-9]+');

    // Guide Management
    Route::get('/guides', [AdminController::class, 'getGuides']);
    Route::get('/guides/{id}', [AdminController::class, 'getGuide'])->where('id', '[0-9]+');
    
    // Brand Rankings
    Route::get('/brand-rankings', [BrandRankingController::class, 'getBrandRankings']);
    Route::get('/brand-rankings/comprehensive', [BrandRankingController::class, 'getComprehensiveRankings']);
});
    Route::get('/guides', [GuideController::class, 'index']);                 // Get all guides
    Route::post('/guides', [GuideController::class, 'store']);                // Create a new guide
    Route::get('/guides/{guide}', [GuideController::class, 'show'])->where('guide', '[0-9]+');          // Get a single guide by ID (route model binding)
    Route::put('/guides/{guide}', [GuideController::class, 'update'])->where('guide', '[0-9]+');        // Update a guide by ID
    Route::delete('/guides/{guide}', [GuideController::class, 'destroy'])->where('guide', '[0-9]+');    // Delete a guide by ID
// Google OAuth routes
Route::get('/google/redirect', [GoogleController::class, 'redirectToGoogle'])
    ->name('google.redirect');

Route::get('/google/callback', [GoogleController::class, 'handleGoogleCallback'])
    ->name('google.callback');

Route::post('/google/auth', [GoogleController::class, 'handleGoogleWithRole'])
    ->name('google.auth');

// Auth routes already included at the top


Route::post('/account/checked', [AccountController::class, 'checkAccount']);

// Stripe webhook (public)
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);

