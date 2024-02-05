<?php

namespace As247\Flysystem\Test;

use League\Flysystem\AdapterInterface;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use League\Flysystem\RootViolationException;
use PHPUnit\Framework\TestCase;
abstract class FlysystemTestCase extends TestCase
{
    /**
     * @var Filesystem
     */
    protected $disk;
    protected function setUp(): void
    {
        $this->disk = new Filesystem(static::createFilesystemAdapter());
    }
    protected abstract static function createFilesystemAdapter(): AdapterInterface;

    public function teardown():void
    {
        foreach ($this->disk->listContents() as $content){
            if($content['type']=='dir'){
                $this->disk->deleteDir($content['path']);
            }else{
                $this->disk->delete($content['path']);
            }
        }
    }
    public function testHasRootDir(){
        $this->assertFalse($this->disk->has('\\'));
        $this->assertFalse($this->disk->has('.'));
        $this->assertFalse($this->disk->has('/'));
        $this->assertFalse($this->disk->has(''));
    }

    public function testRootDirDeletion(){
        try {
            $this->disk->deleteDir('.');
        }catch (\Exception $e){
            $this->assertInstanceOf(RootViolationException::class,$e);
        }
        try {
            $this->disk->deleteDir('//');
        }catch (\Exception $e){
            $this->assertInstanceOf(RootViolationException::class,$e);
        }
        try {
            $this->disk->deleteDir('');
        }catch (\Exception $e){
            $this->assertInstanceOf(RootViolationException::class,$e);
        }
        try {
            $this->disk->deleteDir('..');
        }catch (\Exception $e){
            $this->assertInstanceOf(\LogicException::class,$e);
        }
    }

    public function testHasWithDir()
    {
        $this->disk->createDir('0', []);
        $this->assertTrue($this->disk->has('0'));
        $this->disk->deleteDir('0');
    }
    public function testHasWithFile()
    {
        $adapter = $this->disk;

        $adapter->write('file.txt', 'content', []);
        $this->assertTrue($adapter->has('file.txt'));
        $adapter->delete('file.txt');
    }
    public function testTemporaryUrl(){
        $disk = $this->disk;
        $adapter=$this->disk->getAdapter();
        if(method_exists($adapter,'getTemporaryUrl')) {
            $disk->write('file.txt', 'content', []);
            $temporaryUrl=$adapter->getTemporaryUrl('file.txt');
            $this->assertStringContainsString('http',$temporaryUrl);
            $this->assertEquals('content',file_get_contents($temporaryUrl));
        }else{
            $this->markTestSkipped('Temporary URL not supported');
        }
    }
    public function testMetaData(){
        $this->expectException(FileNotFoundException::class);
        $this->disk->getMetadata('/notexists');
    }
    public function testReadStream()
    {
        $adapter = $this->disk;
        if(!$adapter->has('file.txt')) {
            $adapter->write('file.txt', 'contents', []);
        }
        $result = $adapter->readStream('file.txt');
        $this->assertIsResource($result);
        fclose($result);
        $adapter->delete('file.txt');
    }
    public function testWriteStream()
    {
        $adapter = $this->disk;
        $temp = tmpfile();
        fwrite($temp, 'dummy');
        rewind($temp);
        $adapter->writeStream('dir/file.txt', $temp, ['visibility' => 'public']);
        $this->assertTrue($adapter->has('dir/file.txt'));
        $result = $adapter->read('dir/file.txt');
        $this->assertEquals('dummy', $result);
        $adapter->deleteDir('dir');
    }
    public function testListingNonexistingDirectory()
    {
        $result = $this->disk->listContents('nonexisting/directory');
        $this->assertEquals([], $result);
    }
    public function testUpdateStream()
    {
        $adapter = $this->disk;
        $adapter->write('file.txt', 'initial');
        $temp = tmpfile();
        fwrite($temp, 'dummy');
        $adapter->updateStream('file.txt', $temp);
        $this->assertTrue($adapter->has('file.txt'));
        $this->assertEquals('dummy',$adapter->read('file.txt'));
        $adapter->delete('file.txt');
    }
    public function testCreateZeroDir()
    {
        $this->disk->createDir('0');
        $this->assertTrue($this->disk->has('0'));
        $this->disk->deleteDir('0');
    }
    public function testCreateDirRecurse(){
        $this->disk->createDir('a/b/c');
        $this->assertTrue($this->disk->has('a/b/c'));
        $this->disk->deleteDir('a');
    }
    public function testCopy()
    {
        $adapter = $this->disk;
        $adapter->write('file.ext', 'content', ['visibility' => 'public']);
        //var_dump(OperationException::$lastException);
        $this->assertTrue($adapter->copy('file.ext', 'new.ext'));
        $this->assertTrue($adapter->has('new.ext'));

        $adapter->delete('file.ext');
        $adapter->delete('new.ext');
    }
    public function testCopyNested(){
        $adapter = $this->disk;
        $adapter->write('file.ext', 'content', ['visibility' => 'public']);
        $this->assertTrue($adapter->copy('file.ext', '/a/b/new.ext'));
        $this->assertTrue($adapter->has('/a/b/new.ext'));
    }
    public function testEmptyStream()
    {
        $this->assertTrue($this->disk->writeStream('false', tmpfile(),[]));
        $this->assertTrue($this->disk->writeStream('fail.close', tmpfile(),[]));
    }
    public function testNullPrefix()
    {
        $this->disk->getAdapter()->setPathPrefix('');
        $this->assertEquals('', $this->disk->getAdapter()->getPathPrefix());
    }
    public function testRename()
    {
        $wrote=$this->disk->write('testRename.txt', 'testRename', []);
        $this->assertTrue($wrote);
        $dirname = uniqid().'/'.uniqid();
        $this->assertFalse($this->disk->has($dirname));
        $this->assertFalse($this->disk->has(dirname($dirname)));
        $this->assertTrue($this->disk->rename('testRename.txt', $dirname . '/testRename.txt'));
        $this->assertTrue($this->disk->has(dirname($dirname)));
        $this->assertTrue($this->disk->has($dirname . '/testRename.txt'));
        $this->assertFalse($this->disk->has('testRename.txt'));

    }


    public function testRenameDir()
    {
        $this->disk->write('a/testRenameDir.txt', 'testRename', []);
        $dirname = uniqid();
        $this->assertTrue($this->disk->has('a'));
        $this->assertTrue($this->disk->has('a/testRenameDir.txt'));

        $this->assertFalse($this->disk->has($dirname));

        $this->assertTrue($this->disk->rename('a', $dirname . ''));

        $this->assertTrue($this->disk->has($dirname));
        $this->assertTrue($this->disk->has($dirname . '/testRenameDir.txt'));

        $this->assertFalse($this->disk->has('a'));
        $this->assertFalse($this->disk->has('a/testRenameDir.txt'));

    }
    public function testWriteNested(){
        $this->assertFalse($this->disk->has('a/b/c'));
        $this->assertFalse($this->disk->has('a/b'));
        $this->assertFalse($this->disk->has('a'));
        $this->disk->write('a/b/c/writenested.txt','nested');
        $this->assertTrue($this->disk->has('a'));
        $this->assertTrue($this->disk->has('a/b'));
        $this->assertTrue($this->disk->has('a/b/c'));
        $this->assertTrue($this->disk->has('a/b/c/writenested.txt'));
    }
    public function testMkdirNested(){
        $this->assertFalse($this->disk->has('a/b/c'));
        $this->assertFalse($this->disk->has('a/b'));
        $this->assertFalse($this->disk->has('a'));
        $this->disk->createDir('a/b/c',[]);
        $this->assertTrue($this->disk->has('a'));
        $this->assertTrue($this->disk->has('a/b'));
        $this->assertTrue($this->disk->has('a/b/c'));
    }
    public function testListContents()
    {
        $this->disk->write('dirname/file.txt', 'testListContents', []);
        $contents = $this->disk->listContents('dirname', false);
        $this->assertCount(1, $contents);
        $this->assertArrayHasKey('type', $contents[0]);
    }
    public function testListContentsRecursive()
    {
        $this->disk->write('dirname/file.txt', 'testListContentsRecursive', []);
        $this->disk->write('dirname/other.txt', 'testListContentsRecursive', []);
        $contents = $this->disk->listContents('/', true);
        $this->assertCount(3, $contents);
    }

    public function testGetSize()
    {
        $this->disk->write('dummy.txt', '1234', []);
        $result = $this->disk->getSize('dummy.txt');
        $this->assertIsInt($result);
        $this->assertEquals(4, $result);
    }
    public function testGetTimestamp()
    {
        $this->disk->write('dummy.txt', '1234', []);
        $result = $this->disk->getTimestamp('dummy.txt');
        $this->assertIsInt($result);
    }
    public function testGetMimetype()
    {
        $this->disk->write('text.txt', 'contents', []);
        $result = $this->disk->getMimetype('text.txt');
        $this->assertEquals('text/plain', $result);
    }
    public function testCreateDirFail()
    {
        $this->disk->write('fail.plz','contents');
        $this->assertFalse($this->disk->createDir('fail.plz'));
    }
    public function testCreateDirDefaultVisibility()
    {
        $this->disk->createDir('test-dir');
        $output = $this->disk->getVisibility('test-dir');
        $this->assertEquals('private', $output);

    }
    public function testVisibilityPublish()
    {
        $this->disk->createDir('test-dir');
        $this->disk->setVisibility('test-dir', 'public');
        $output = $this->disk->getVisibility('test-dir');
        $this->assertEquals('public', $output);
        $this->disk->setVisibility('test-dir', 'private');
        $output = $this->disk->getVisibility('test-dir');
        $this->assertEquals('private', $output);

    }
    public function testDeleteDir()
    {
        $this->disk->write('nested/dir/path.txt', 'contents');
        $this->assertTrue($this->disk->has('nested/dir'));
        $this->disk->deleteDir('nested');
        $this->assertFalse($this->disk->has('nested/dir/path.txt'));
        $this->assertFalse($this->disk->has('nested/dir'));

    }

    public function testMimetypeFallbackOnExtension()
    {
        $this->disk->write('test.xlsx', 'a');
        $this->assertEquals('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->disk->getMimetype('test.xlsx'));
    }
    public function testDeleteFileShouldReturnTrue(){
        $this->disk->write('delete.txt','something');
        $this->assertTrue($this->disk->delete('delete.txt'));
    }
    public function testDeleteMissingFileShouldReturnFalse(){
        $this->expectException(FileNotFoundException::class);
        $this->disk->delete('missing.txt');
    }
    /*
	public function testUploadBigFile(){

		$stream=fopen(__DIR__.'/BigFile.bin','rb');
        $meta=fstat($stream);
		$start=microtime(true);
		$this->disk->writeStream('urn_oid_123',$stream);
		$writeTime=microtime(true)-$start;
		$start=microtime(true);
		$stream_read=$this->disk->readStream('urn_oid_123');
		$this->assertIsResource($stream_read);
		$readTime=microtime(true)-$start;
		$this->assertLessThan($writeTime,$readTime);
		$this->assertEquals($meta['size'],fstat($stream_read)['size']);


	}
    */
}