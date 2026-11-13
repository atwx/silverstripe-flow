<%-- Preview of a deletion run with the button to run it. --%>
<div class="fdk-manager">
    <div class="fdk-page-header">
        <h1 class="fdk-page-title">$Title</h1>
        <div class="fdk-page-actions"><a href="$BackLink" class="btn btn-sm btn-ghost">← <%t Atwx\Flow\Retention\DeletionRunController.BACK 'Back' %></a></div>
    </div>
    <div class="card bg-base-100 shadow-sm max-w-3xl">
        <div class="card-body">
            <% if $Rule.Description %><p>$Rule.Description</p><% end_if %>
            <p><strong>$Due</strong> $Rule.ModelName <%t Atwx\Flow\Retention\DeletionRunController.DUE 'due (dated before {date})' date=$Rule.Cutoff %></p>
            <% if $Labels %>
            <ul class="list-disc ps-6 text-sm">
                <% loop $Labels %><li>$Label</li><% end_loop %>
            </ul>
            <% if $More %><p class="text-sm opacity-70"><%t Atwx\Flow\Retention\DeletionRunController.MORE 'and {count} more' count=$More %></p><% end_if %>
            <% end_if %>
            <p class="text-sm opacity-70"><%t Atwx\Flow\Retention\DeletionRunController.CASCADE 'Deleted with everything attached to them (history, comments, mails, documents, files). This cannot be undone.' %></p>
            <% if $Due && $canEdit %>
            <form method="post" action="$RunLink" class="card-actions justify-end mt-2"
                  onsubmit="return confirm('<%t Atwx\Flow\Retention\DeletionRunController.CONFIRM 'Really delete {count} records?' count=$Due %>')">
                <input type="hidden" name="SecurityID" value="$SecurityTokenValue">
                <button type="submit" class="btn btn-error"><%t Atwx\Flow\Retention\DeletionRunController.RUN 'Delete now' %></button>
            </form>
            <% end_if %>
        </div>
    </div>
</div>
