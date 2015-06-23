<?php
namespace SilverStripe\BlowGun\Command;

use SilverStripe\BlowGun\Model\Command;
use SilverStripe\BlowGun\Model\Status;
use SilverStripe\BlowGun\Model\Message;
use SilverStripe\BlowGun\Service\SQSHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ListenCommand
 *
 * Disclaimer: Since this command will be running as a daemon, be very careful
 * what state is added to $this and other classes. Preferable unset and
 * re-instantiate objects.
 *
 * @package SilverStripe\BlowGun\Command
 */
class ListenCommand extends BaseCommand {

	/**
	 * @var string
	 */
	protected $siteRoot = '';

	/**
	 * @var string
	 */
	protected $scriptDir = '';

	/**
	 * @var SQSHandler
	 */
	protected $handler = null;

	protected function configure() {

		parent::configure();
		$this->setName('listen');
		$this->addArgument('cluster');
		$this->addArgument('stack');
		$this->addArgument('env');
		$this->addArgument('site-root');
		$this->addArgument('script-dir');
		// @todo(stig): add a verbose flag
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return bool|void
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {

		parent::execute($input, $output);

		$this->handler = new SQSHandler($this->profile, $this->region);
		$this->siteRoot = $this->getDirectoryFromInput($input, 'site-root');
		$this->scriptDir = $this->getDirectoryFromInput($input, 'script-dir');
		$queueName = $this->getQueueNameFromInput($input);

		while(true) {
			$messages = $this->handler->fetch($queueName);
			foreach($messages as $message) {
				$this->handleMessage($message);
			}
		}
	}

	/**
	 * @param InputInterface $input
	 * @param string $argumentName
	 *
	 * @return string
	 */
	protected function getDirectoryFromInput(InputInterface $input, $argumentName) {

		$dirName = trim($input->getArgument($argumentName));

		if(!$dirName) {
			$errorMsg = sprintf("Missing '%s' argument!", $argumentName);
			$this->log->addCritical($errorMsg);
			throw new \RuntimeException($errorMsg);
		}

		if(!file_exists($dirName)) {
			$errorMsg = sprintf("Directory '%s' does not exist!", $dirName);
			$this->log->addCritical($errorMsg);
			throw new \RuntimeException($errorMsg);
		}

		if(!is_dir($dirName)) {
			$errorMsg = sprintf("'%s' isn't a directory!", $argumentName);
			$this->log->addCritical($errorMsg);
			throw new \RuntimeException($errorMsg);
		}

		return $dirName;
	}

	/**
	 * @param InputInterface $input
	 *
	 * @return string
	 */
	protected function getQueueNameFromInput(InputInterface $input) {

		$queueName = sprintf('%s-%s-%s',
			$input->getArgument('cluster'),
			$input->getArgument('stack'),
			$input->getArgument('env')
		);

		return $queueName;
	}

	/**
	 * @param Message $message
	 */
	protected function handleMessage(Message $message) {

		$this->logNotice('Received message', $message);
		if(!$message->isValid()) {
			$this->logError($message->getErrorMessage(), $message);
			return;
		}

		// At this point there is no action a blowgun worker can do with
		// a status oldMessage, so just log it and delete the oldMessage
		if($message->getType() == 'status') {
			$this->logNotice($message->getMessage(), $message);
			$this->handler->delete($message);
			$this->logNotice('Deleted message', $message);
			return;
		}

		$command = new Command($message, $this->scriptDir, $this->siteRoot);
		$status = $command->run();

		foreach($status->getErrors() as $error) {
			$this->logError($error, $message);
		}

		foreach ($status->getNotices() as $notice) {
			$this->logNotice($notice, $message);
		}

		if($message->getRespondTo()) {
			$this->sendResponse($message, $status);
			$this->logNotice('Sent response', $message);
		}
		$message->deleteFromQueue();
		$this->logNotice('Deleted message', $message);
	}

	/**
	 * @param Message $oldMessage
	 * @param Status $status
	 */
	protected function sendResponse(Message $oldMessage, Status $status) {

		$responseMsg = new Message($oldMessage->getRespondTo(), $this->handler);
		$responseMsg->setType('status');
		$responseMsg->setResponseId($oldMessage->getResponseId());
		$responseMsg->setSuccess($status->isSuccessful());

		foreach($status->getData() as $key => $value) {
			$responseMsg->setArgument($key, $value);
		}
		if($status->getErrors()) {
			$responseMsg->setErrorMessage(
				implode(PHP_EOL, $status->getErrors())
			);
		}
		if($status->getNotices()) {
			$responseMsg->setMessage(
				implode(PHP_EOL, $status->getNotices())
			);
		}
		$responseMsg->send();
	}

	/**
	 * @param $string
	 * @param Message|string $message
	 */
	protected function logError($string, Message $message) {

		$this->log->addError($string, [$message->getQueue(), $message->getMessageId()]);
	}

	/**
	 * @param string $string
	 * @param Message|string $message
	 */
	protected function logNotice($string, Message $message) {

		$this->log->addNotice($string, [$message->getQueue(), $message->getMessageId()]);
	}
}
