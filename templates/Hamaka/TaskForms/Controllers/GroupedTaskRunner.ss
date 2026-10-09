$Header.RAW
$Info.RAW

<div class="task">
    <% loop $Categories %>
        <div class="task__panel">
            <h2 class="task__category">$Title</h2>
            <div class="task__list">
                <% loop $Tasks %>
                    <div class="task__item">
                        <div>
                            <h3 class="task__title">$Title</h3>
                            <div class="task__description">
                                $Description
                                <% if $Help %>
                                    <details class="task__help">
                                        <summary>Display additional information</summary>
                                        $Help
                                    </details>
                                <% end_if %>
                            </div>
                            <% if $Parameters %>
                                Parameters:
                                <% include SilverStripe/Dev/Parameters %>
                            <% end_if %>
                        </div>
                        <div>
                            <a href="{$TaskLink.ATT}" class="task__button">Run task</a>
                        </div>
                    </div>
                <% end_loop %>
            </div>
        </div>
    <% end_loop %>
</div>

$Footer.RAW
