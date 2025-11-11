# Brand Fund Checking When Approving Creator Proposals

## Overview

When a brand approves a creator proposal, the system verifies that the brand has funds/payment capability in the platform before allowing the approval to proceed.

## Fund Checking Flow

### Step 1: Payment Method Verification

The system first checks if the brand has a payment method configured:

- **Stripe Payment Method**: Checks if `stripe_customer_id` and `stripe_payment_method_id` exist on the user model
- **Brand Payment Methods**: Checks for active payment methods in the `brand_payment_methods` table

**If no payment method exists:**
- Creates a Stripe checkout session in "setup" mode
- Redirects brand to Stripe to add a payment method
- Returns HTTP 402 (Payment Required) with redirect URL

### Step 2: Contract Funding Verification

If a payment method exists, the system checks for contracts related to the application that need funding:

1. **Find Related Contracts**: Searches for contracts related to:
   - The same campaign and creator (via offers)
   - The same creator (direct contracts without offers)

2. **Check Funding Status**: Uses `Contract::needsFunding()` to verify if contracts are funded

**A contract is considered "funded" if:**
- It has a `JobPayment` record with status `'completed'`, OR
- Contract status is `'active'` or `'completed'` (payment was processed), OR
- Payment status is `'completed'` or `'processing'`

**A contract "needs funding" if:**
- Status is `'pending'` with `workflow_status` `'payment_pending'`, OR
- No payment record exists, OR
- Payment status is `'pending'` or `'failed'`

**If contracts need funding:**
- Creates a Stripe checkout session in "payment" mode
- Redirects brand to complete payment
- Returns HTTP 402 (Payment Required) with redirect URL

### Step 3: Approval Process

Only proceeds with approval if:
- Payment method exists, AND
- All related contracts are funded (or no contracts exist yet)

## Helper Methods

### Contract Model Methods

#### `isFunded(): bool`
Checks if a contract has been funded by the brand.

```php
$contract = Contract::find($id);
if ($contract->isFunded()) {
    // Contract is funded
}
```

#### `needsFunding(): bool`
Checks if a contract needs funding from the brand.

```php
$contract = Contract::find($id);
if ($contract->needsFunding()) {
    // Contract needs funding
}
```

#### `checkBrandFundsForApplication(int $brandId, int $campaignId, int $creatorId): array`
Static method to check brand funds for a specific application.

```php
$fundStatus = Contract::checkBrandFundsForApplication(
    $brandId = 1,
    $campaignId = 5,
    $creatorId = 10
);

// Returns:
// [
//     'has_funded' => true/false,
//     'all_funded' => true/false,
//     'has_unfunded' => true/false,
//     'contracts' => Collection,
//     'funded_contracts' => Collection,
//     'contracts_needing_funding' => Collection,
// ]
```

## Code Location

- **Controller**: `app/Http/Controllers/CampaignApplicationController.php`
  - Method: `approve()`
  - Lines: 148-514

- **Model**: `app/Models/Contract.php`
  - Methods: `isFunded()`, `needsFunding()`, `checkBrandFundsForApplication()`
  - Lines: 340-384, 99-142

## Example Usage

### Check if contract is funded:
```php
$contract = Contract::find($id);
if ($contract->isFunded()) {
    echo "Contract is funded";
} else {
    echo "Contract needs funding";
}
```

### Check brand funds for an application:
```php
$application = CampaignApplication::find($applicationId);
$fundStatus = Contract::checkBrandFundsForApplication(
    $application->campaign->brand_id,
    $application->campaign_id,
    $application->creator_id
);

if ($fundStatus['all_funded']) {
    echo "All contracts are funded";
} elseif ($fundStatus['has_unfunded']) {
    echo "Some contracts need funding";
    foreach ($fundStatus['contracts_needing_funding'] as $contract) {
        echo "Contract #{$contract->id} needs funding";
    }
}
```

## Payment Flow

1. **Brand approves proposal** → System checks payment method
2. **If no payment method** → Redirect to Stripe setup
3. **If payment method exists** → Check for contracts needing funding
4. **If contracts need funding** → Redirect to Stripe payment
5. **After payment** → Stripe webhook processes payment
6. **Payment processed** → Contract status updated to `'active'`
7. **Contract funded** → Brand can approve proposals

## Notes

- Contracts are typically created when offers are accepted (after proposal approval)
- The fund check happens during proposal approval to ensure brands can pay
- If no contracts exist yet, approval proceeds normally
- The system uses Stripe for payment processing
- All payments are held in escrow until contract completion

