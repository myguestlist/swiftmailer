<?php

/*
 * This file is part of MyGuestlist for Swiftmailer.
 * (c) 2008 MyGuestlist
 *
 */

/**
 * Proxy for quoted-printable content encoders.
 *
 * Switches on the best QP encoder implementation for current charset.
 *
 * @package    Swift
 * @subpackage Mime
 * @author     Alex Ilievski
 */
class Swift_Mime_ContentEncoder_MGLQpContentEncoderProxy implements Swift_Mime_ContentEncoder
{
    /**
     * @var Swift_Mime_ContentEncoder_MGLQpContentEncoder
     */
    private $safeEncoder;

    /**
     * @var Swift_Mime_ContentEncoder_NativeQpContentEncoder
     */
    private $nativeEncoder;

    /**
     * @var null|string
     */
    private $charset;

    /**
     * Constructor.
     *
     * @param Swift_Mime_ContentEncoder_MGLQpContentEncoder       $safeEncoder
     * @param Swift_Mime_ContentEncoder_NativeQpContentEncoder $nativeEncoder
     * @param string|null                                      $charset
     */
    public function __construct(Swift_Mime_ContentEncoder_MGLQpContentEncoder $safeEncoder, Swift_Mime_ContentEncoder_NativeQpContentEncoder $nativeEncoder, $charset)
    {
        $this->safeEncoder = $safeEncoder;
        $this->nativeEncoder = $nativeEncoder;
        $this->charset = $charset;
    }

    /**
     * {@inheritdoc}
     */
    public function charsetChanged($charset)
    {
        $this->charset = $charset;
    }

    /**
     * {@inheritdoc}
     */
    public function encodeByteStream(Swift_OutputByteStream $os, Swift_InputByteStream $is, $firstLineOffset = 0, $maxLineLength = 0)
    {
        $this->getEncoder()->encodeByteStream($os, $is, $firstLineOffset, $maxLineLength);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'quoted-printable';
    }

    /**
     * {@inheritdoc}
     */
    public function encodeString($string, $firstLineOffset = 0, $maxLineLength = 0)
    {
        return $this->getEncoder()->encodeString($string, $firstLineOffset, $maxLineLength);
    }

    /**
     * @return Swift_Mime_ContentEncoder
     */
    private function getEncoder()
    {
        return 'utf-8' === $this->charset ? $this->nativeEncoder : $this->safeEncoder;
    }
}
