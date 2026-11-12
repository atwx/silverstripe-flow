<?php

namespace Atwx\Flow\Service;

use Atwx\Flow\Controller\FollowUpController;
use Atwx\Flow\Model\Comment;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;
use Throwable;

/**
 * Daily mail to every member with open follow-ups and to-dos that are due or
 * overdue: one mail per member listing them with links to the records (sake
 * flow:followup-digest, run by cron once a day).
 *
 * Left out: entries without a member, records the member may not view
 * (the record controller's view permission, as in the list of follow-ups),
 * members without a valid address, members who turned the mail off
 * (Member.FlowDigestOff) and members who already got it today.
 */
class FollowUpDigest
{
    use Injectable;

    /**
     * Due follow-ups by member ID.
     *
     * @return array<int, array{member: Member, followUps: array<int, Comment>}>
     */
    public function due(?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $result = [];
        $open = Comment::get()
            ->filter([
                'IsDone' => false,
                'Kind' => Comment::TASK_KINDS,
                'DueDate:LessThanOrEqual' => $today,
                'MemberID:GreaterThan' => 0,
            ])
            ->sort(['DueDate' => 'ASC', 'DueTime' => 'ASC', 'ID' => 'ASC']);
        foreach ($open as $followUp) {
            $memberID = (int) $followUp->MemberID;
            if (!isset($result[$memberID])) {
                $member = Member::get()->byID($memberID);
                if (!$member) {
                    continue;
                }
                $result[$memberID] = ['member' => $member, 'followUps' => []];
            }
            if ($this->mayView($followUp, $result[$memberID]['member'])) {
                $result[$memberID]['followUps'][] = $followUp;
            }
        }
        return array_filter($result, fn (array $entry) => $entry['followUps'] !== []);
    }

    /**
     * Sends the mails; errors are logged and reported, never thrown.
     *
     * @param bool $force Also to members who already got it today
     * @return array<int, string> One line per member
     */
    public function send(?string $today = null, bool $dryRun = false, bool $force = false): array
    {
        $today = $today ?? date('Y-m-d');
        $report = [];
        foreach ($this->due($today) as $entry) {
            $member = $entry['member'];
            $count = count($entry['followUps']);
            $address = strtolower(trim((string) $member->Email));
            $line = sprintf('%s <%s>: %d', $member->getName(), $address, $count);
            if ($member->FlowDigestOff) {
                $report[] = $line . ' (turned off)';
                continue;
            }
            if (!$force && $member->FlowDigestSentOn === $today) {
                $report[] = $line . ' (already sent today)';
                continue;
            }
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $report[] = $line . ' (no valid address)';
                continue;
            }
            if ($dryRun) {
                $report[] = $line . ' (dry run)';
                continue;
            }
            try {
                $this->email($member, $entry['followUps'], $today)->send();
                $member->FlowDigestSentOn = $today;
                $member->write();
                $report[] = $line . ' sent';
            } catch (Throwable $e) {
                $report[] = $line . ' failed: ' . $e->getMessage();
                Injector::inst()->get(LoggerInterface::class)->error(
                    'Flow follow-up digest to ' . $address . ' failed: ' . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }
        return $report;
    }

    /**
     * @param array<int, Comment> $followUps
     */
    public function email(Member $member, array $followUps, string $today): Email
    {
        $lines = [];
        $items = [];
        foreach ($followUps as $followUp) {
            $record = $followUp->Record();
            $date = $followUp->getDueLabel();
            $overdue = $followUp->DueDate < $today ? ' (' . _t(self::class . '.OVERDUE', 'overdue') . ')' : '';
            $kind = $followUp->Kind === Comment::TODO ? $followUp->getKindLabel() . ': ' : '';
            $text = sprintf('%s%s: %s – %s%s', $date, $overdue, $record->getTitle(), $kind, $followUp->Text);
            $url = $this->caseUrl($followUp);
            $lines[] = '- ' . $text . ($url ? "\n  " . $url : '');
            $html = htmlspecialchars($text, ENT_QUOTES);
            $link = $url ? '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $html . '</a>' : $html;
            $items[] = '<li>' . $link . '</li>';
        }
        $name = $member->FirstName ?: $member->getName();
        $greeting = _t(self::class . '.GREETING', 'Hello {name},', ['name' => $name]);
        $intro = _t(self::class . '.INTRO', 'these follow-ups are due:');
        $listUrl = $this->listUrl();
        $all = $listUrl ? _t(self::class . '.ALL', 'All your follow-ups: {url}', ['url' => $listUrl]) : '';

        $text = $greeting . "\n\n" . $intro . "\n\n" . implode("\n", $lines) . ($all ? "\n\n" . $all : '') . "\n";
        $html = '<p>' . htmlspecialchars($greeting, ENT_QUOTES) . '</p><p>' . htmlspecialchars($intro, ENT_QUOTES)
            . '</p><ul>' . implode('', $items) . '</ul>'
            . ($listUrl ? '<p><a href="' . htmlspecialchars($listUrl, ENT_QUOTES) . '">'
                . htmlspecialchars(_t(self::class . '.ALL_LINK', 'All your follow-ups'), ENT_QUOTES) . '</a></p>' : '');

        $email = Email::create()
            ->setTo($member->Email)
            ->setSubject(_t(
                self::class . '.SUBJECT',
                'Due follow-ups ({count})',
                ['count' => count($followUps)]
            ))
            ->text($text)
            ->html($html);
        $from = (string) (MailService::config()->get('from') ?: Email::config()->get('admin_email'));
        if ($from !== '') {
            $email->setFrom($from);
        }
        return $email;
    }

    /**
     * Same rule as the list of follow-ups: the member may view the list of the
     * case controller (or the case file itself when there is no controller).
     */
    private function mayView(Comment $followUp, Member $member): bool
    {
        return $followUp->canView($member);
    }

    private function caseUrl(Comment $followUp): ?string
    {
        $link = FollowUpController::recordLink($followUp->Record());
        return $link ? Director::absoluteURL($link) : null;
    }

    /**
     * The routed list of follow-ups, view "Mine, due" (null when not routed).
     */
    private function listUrl(): ?string
    {
        foreach ((array) Director::config()->get('rules') as $target) {
            $class = is_array($target) ? ($target['Controller'] ?? null) : $target;
            if (is_string($class) && class_exists($class) && is_a($class, FollowUpController::class, true)) {
                return Director::absoluteURL(singleton($class)->Link() . '?view=mine-due');
            }
        }
        return null;
    }
}
