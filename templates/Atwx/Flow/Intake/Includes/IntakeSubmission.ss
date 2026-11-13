<div class="space-y-4">
    <div class="flex flex-wrap gap-2 items-center">
        <span class="badge badge-outline">$TypeTitle</span>
        <span class="badge <% if $Submission.Status == 'new' %>badge-primary<% else %>badge-ghost<% end_if %>">$Submission.StatusTitle</span>
        <span class="text-sm opacity-70"><%t Atwx\\Flow\\Intake\\IntakeController.RECEIVED 'Received {date}' date=$Submission.Created.Nice %></span>
        <% if $Submission.NotifyError %>
            <span class="badge badge-warning" title="$Submission.NotifyError"><%t Atwx\\Flow\\Intake\\IntakeController.NOTIFY_FAILED 'Notification failed' %></span>
        <% end_if %>
    </div>
    <% if $TakeoverRecords.Count %>
        <div class="alert">
            <span>
                <%t Atwx\\Flow\\Intake\\IntakeController.TAKEN_OVER_INTO 'Taken over by {name} into:' name=$Submission.TakenOverBy.Name %>
                <% loop $TakeoverRecords %><% if $Link %><a class="link" href="$Link">$Title</a><% else %>$Title<% end_if %><% if not $IsLast %>, <% end_if %><% end_loop %>
            </span>
        </div>
    <% end_if %>
    <div class="fdk-table-wrapper overflow-x-auto">
        <table class="table table-zebra w-full">
            <tbody>
                <% loop $Rows %>
                    <% if $Rows.Count %>
                        <tr><th colspan="2" class="pt-4 text-base">$Title</th></tr>
                        <% loop $Rows %>
                            <% if $Rows.Count %>
                                <tr><th colspan="2" class="font-medium">$Title</th></tr>
                                <% loop $Rows %>
                                    <tr><th class="w-1/4 font-medium text-base-content/70 pl-8">$Title</th><td>$Value</td></tr>
                                <% end_loop %>
                            <% else %>
                                <tr><th class="w-1/4 font-medium text-base-content/70 pl-6">$Title</th><td>$Value</td></tr>
                            <% end_if %>
                        <% end_loop %>
                    <% else %>
                        <tr><th class="w-1/4 font-medium text-base-content/70 whitespace-nowrap">$Title</th><td>$Value</td></tr>
                    <% end_if %>
                <% end_loop %>
            </tbody>
        </table>
    </div>
    <% if $Files.Count %>
        <div>
            <h3 class="font-semibold mb-2"><%t Atwx\\Flow\\Intake\\IntakeController.FILES 'Files' %></h3>
            <ul class="list-disc pl-6">
                <% loop $Files %><li>$FieldTitle: <a class="link" href="$Link">$Name</a></li><% end_loop %>
            </ul>
        </div>
    <% end_if %>
</div>
