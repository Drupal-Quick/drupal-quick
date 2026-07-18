<?php

namespace DrupalQuick\Tests\Unit\Environment;

use DrupalQuick\Environment\Comingling;
use PHPUnit\Framework\TestCase;

final class CominglingTest extends TestCase {

  private string $ddevProject;
  private string $bareProject;

  protected function setUp(): void {
    $this->ddevProject = sys_get_temp_dir() . '/dq-comingle-ddev-' . uniqid();
    $this->bareProject = sys_get_temp_dir() . '/dq-comingle-bare-' . uniqid();
    mkdir($this->ddevProject . '/.ddev', 0777, TRUE);
    file_put_contents($this->ddevProject . '/.ddev/config.yaml', "name: x\n");
    mkdir($this->bareProject, 0777, TRUE);
  }

  protected function tearDown(): void {
    @unlink($this->ddevProject . '/.ddev/config.yaml');
    @rmdir($this->ddevProject . '/.ddev');
    @rmdir($this->ddevProject);
    @rmdir($this->bareProject);
  }

  public function testHostRunInDdevProjectIsRefused(): void {
    $msg = Comingling::hostInDdevProjectError($this->ddevProject, ['IS_DDEV_PROJECT' => '', 'DQ_ALLOW_HOST' => '']);
    $this->assertNotNull($msg);
    $this->assertStringContainsString('DDEV', $msg);
  }

  public function testInsideDdevProceeds(): void {
    $this->assertNull(Comingling::hostInDdevProjectError($this->ddevProject, ['IS_DDEV_PROJECT' => 'true', 'DQ_ALLOW_HOST' => '']));
  }

  public function testEscapeHatchProceeds(): void {
    $this->assertNull(Comingling::hostInDdevProjectError($this->ddevProject, ['IS_DDEV_PROJECT' => '', 'DQ_ALLOW_HOST' => '1']));
  }

  public function testBareHostProjectProceeds(): void {
    // No .ddev/config.yaml → not a DDEV project → nothing to guard.
    $this->assertNull(Comingling::hostInDdevProjectError($this->bareProject, ['IS_DDEV_PROJECT' => '', 'DQ_ALLOW_HOST' => '']));
  }

  public function testMissingEnvKeysTreatedAsUnset(): void {
    $this->assertNotNull(Comingling::hostInDdevProjectError($this->ddevProject, []));
  }

}
