<?php
/**
 * /templates/borrow_history_row.php
 * This template renders a single row for the transaction history table.
 */
?>
<tr class="bg-white/50 hover:bg-white/70 transition-colors duration-200" data-borrow-id="<?php echo htmlspecialchars($tx['borrow_id'] ?? ''); ?>" data-mongo-id="<?php echo htmlspecialchars((string)$tx['_id']); ?>" data-student-email="<?php echo htmlspecialchars($tx['student_details']['email'] ?? ''); ?>">
    <td class="px-6 py-4 align-top font-mono text-xs text-indigo-600 font-semibold">
        <?php echo htmlspecialchars($tx['borrow_id'] ?? 'N/A'); ?>
    </td>
    <td class="px-6 py-4 align-top">
        <div class="flex items-start gap-4">
            <img src="<?php echo htmlspecialchars($tx['book_details']['thumbnail'] ?? 'https://placehold.co/80x120/e2e8f0/475569?text=N/A'); ?>" class="w-12 h-16 object-cover rounded-md shadow-sm flex-shrink-0" alt="Book Cover">
            <div>
                <div class="font-semibold text-slate-800 text-base leading-tight"><?php echo htmlspecialchars($tx['book_details']['title'] ?? ($tx['title'] ?? 'Unknown Book')); ?></div>
                <div class="font-mono text-xs text-slate-500 mt-1">ISBN: <?php echo htmlspecialchars($tx['isbn'] ?? 'N/A'); ?></div>
            </div>
        </div>
    </td>
    <td class="px-6 py-4 align-top">
        <div class="flex items-center gap-3">
            <img src="<?php echo htmlspecialchars($tx['student_details']['image'] ?? 'https://placehold.co/40x40/e2e8f0/475569?text=U'); ?>" class="w-10 h-10 object-cover rounded-full shadow-sm" alt="Student Photo">
            <div>
                <div class="font-semibold text-slate-800"><?php echo htmlspecialchars($tx['student_details']['first_name'] ?? ($tx['student_name'] ?? 'Unknown Student')); ?> <?php echo htmlspecialchars($tx['student_details']['last_name'] ?? ''); ?></div>
                <div class="text-xs text-slate-500"><?php echo htmlspecialchars($tx['student_no'] ?? 'N/A'); ?></div>
            </div>
        </div>
    </td>
    <td class="px-6 py-4 text-xs align-top whitespace-nowrap">
        <div><span class="font-medium text-slate-600">Borrowed:</span> <?php echo (new DateTime($tx['borrow_date']))->format('M d, Y'); ?></div>
        <div class="mt-1"><span class="font-medium text-slate-600">Due:</span> <?php echo (new DateTime($tx['due_date']))->format('M d, Y'); ?></div>
    </td>
    <td class="px-6 py-4 align-top">
        <div class="flex flex-col gap-1">
            <?php if (isset($tx['return_date']) && !empty($tx['return_date'])) : ?>
                <span class="inline-flex items-center gap-1.5 py-1 px-2.5 rounded-full text-xs font-medium bg-slate-200/60 text-slate-700 w-fit">Returned</span>
            <?php else : ?>
                <span class="inline-flex items-center gap-1.5 py-1 px-2.5 rounded-full text-xs font-medium bg-blue-200/60 text-blue-800 w-fit">Borrowed</span>
            <?php endif; ?>
            
            <?php if (isset($tx['penalty']) && $tx['penalty'] > 0) : ?>
                <span class="font-mono text-sm font-bold text-red-600">₱<?php echo number_format((float)$tx['penalty'], 2); ?></span>
            <?php endif; ?>
        </div>
    </td>
    <td class="px-6 py-4 align-top">
        <div class="flex justify-center items-center gap-2">
             <button onclick="showHistoryReceipt(this.closest('tr'))" class="action-button p-2 text-slate-500 rounded-md hover:bg-slate-200/70 hover:text-slate-800" title="View Receipt"><i data-lucide="receipt" class="w-4 h-4"></i></button>
             <button onclick="sendManualReceipt('<?php echo (string)$tx['_id']; ?>', '<?php echo htmlspecialchars($tx['student_details']['email'] ?? ''); ?>', this)" class="action-button p-2 text-slate-500 rounded-md hover:bg-blue-200/70 hover:text-blue-700 disabled:opacity-50 disabled:transform-none" <?php echo empty($tx['student_details']['email']) ? 'disabled' : ''; ?> title="Email Receipt"><i data-lucide="mail" class="w-4 h-4"></i></button>
             <button onclick="deleteTransaction('<?php echo (string)$tx['_id']; ?>')" class="action-button p-2 text-slate-500 rounded-md hover:bg-red-200/70 hover:text-red-700" title="Delete Transaction"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
        </div>
    </td>
</tr>