<%-- Second step of a takeover: differences to the data already there (IntakeController::takeover()). --%>
<div class="fdk-manager">
    <div class="fdk-page-header">
        <h1 class="fdk-page-title">$Title</h1>
        <div class="fdk-page-actions">
            <a href="$BackLink" class="btn btn-sm btn-ghost"><%t Atwx\Flow\Intake\IntakeController.CANCEL 'Cancel' %></a>
        </div>
    </div>
    <% include Atwx\\SilverstripeFrontdeskKit\\Includes\\FlashMessage %>
    <form method="post" action="$FormAction" class="space-y-6 fdk-takeover-conflicts">
        <input type="hidden" name="SecurityID" value="$Token.ATT">
        <input type="hidden" name="Signature" value="$Signature.ATT">
        <% if $Choice %><input type="hidden" name="choice" value="$Choice.ATT"><% end_if %>
        <div>
            <% if $ChoiceTitle %><p class="font-medium">$ChoiceTitle</p><% end_if %>
            <p class="text-base-content/70"><%t Atwx\Flow\Intake\IntakeController.CONFLICTS_INTRO 'These details differ from the data already there. Choose per field what applies.' %></p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm" data-fdk-takeover-all="current" onclick="this.form.querySelectorAll('input[type=radio][value=current]').forEach(r => r.checked = true)"><%t Atwx\Flow\Intake\IntakeController.ALL_CURRENT 'Keep all current' %></button>
            <button type="button" class="btn btn-sm" data-fdk-takeover-all="new" onclick="this.form.querySelectorAll('input[type=radio][value=new]').forEach(r => r.checked = true)"><%t Atwx\Flow\Intake\IntakeController.ALL_NEW 'Take over all new' %></button>
        </div>
        <% loop $Groups %>
        <section>
            <% if $Title %><h2 class="font-semibold text-lg mb-2">$Title</h2><% end_if %>
            <div class="fdk-table-wrapper">
                <table class="table table-fixed w-full fdk-stack">
                    <thead><tr>
                        <th class="w-1/4"><%t Atwx\Flow\Intake\IntakeController.CONFLICT_FIELD 'Field' %></th>
                        <th class="w-3/8"><%t Atwx\Flow\Intake\IntakeController.CONFLICT_CURRENT 'Current' %></th>
                        <th class="w-3/8"><%t Atwx\Flow\Intake\IntakeController.CONFLICT_NEW 'New' %></th>
                    </tr></thead>
                    <tbody>
                    <% loop $Rows %>
                    <tr>
                        <th class="font-medium">$Label</th>
                        <td data-label="<%t Atwx\Flow\Intake\IntakeController.CONFLICT_CURRENT 'Current' %>">
                            <label class="flex items-start gap-2 cursor-pointer">
                                <input type="radio" class="radio radio-sm mt-0.5" name="$Name.ATT" value="current"<% if not $TakesNew %> checked<% end_if %>>
                                <span class="break-words min-w-0"><% if $Current %>$Current<% else %><span class="text-base-content/50">–</span><% end_if %></span>
                            </label>
                        </td>
                        <td data-label="<%t Atwx\Flow\Intake\IntakeController.CONFLICT_NEW 'New' %>">
                            <label class="flex items-start gap-2 cursor-pointer">
                                <input type="radio" class="radio radio-sm mt-0.5" name="$Name.ATT" value="new"<% if $TakesNew %> checked<% end_if %>>
                                <span class="break-words min-w-0"><% if $New %>$New<% else %><span class="text-base-content/50">–</span><% end_if %></span>
                            </label>
                        </td>
                    </tr>
                    <% end_loop %>
                    </tbody>
                </table>
            </div>
        </section>
        <% end_loop %>
        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary"><%t Atwx\Flow\Intake\IntakeController.TAKE_OVER 'Take over' %></button>
            <a href="$BackLink" class="btn btn-ghost"><%t Atwx\Flow\Intake\IntakeController.CANCEL 'Cancel' %></a>
        </div>
    </form>
</div>
