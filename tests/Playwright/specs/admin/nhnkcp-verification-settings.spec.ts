/**
 * E2E: NHN KCP 휴대폰 본인확인 플러그인 환경설정 화면
 *
 * 운영 모드로 전환하면 경고 배너가 나타나고, 자격증명을 비운 채 저장하면 저장이 막히면서
 * 어떤 항목이 필요한지 입력칸 옆에 표시되는지 브라우저에서 확인한다. 단위/기능 테스트는
 * 서버 규칙만 잠그므로, 화면에서 실제로 안내가 보이는지는 여기서 잠근다.
 *
 * KCP 표준창 내부(외부 도메인)는 자동화 대상이 아니다 — 인증창이 열리는 것까지만 확인 가능하며
 * 통신사 인증 완주는 실 휴대폰이 필요해 사람이 검증한다.
 *
 * @scenario mode=test,live_credentials=complete + mode=live,live_credentials=missing_site_cd
 * @effects live_mode_requires_site_cd_and_enc_key + test_mode_saves_without_live_credentials
 */
import { test, expect, authenticatePage } from '../../fixtures/nhnkcp-auth';

const SETTINGS_PATH = '/admin/plugins/sirsoft-verification_nhnkcp/settings';

test.describe('NHN KCP 본인확인 환경설정', () => {
  test.beforeEach(async ({ page, pluginSettingsToken }) => {
    await authenticatePage(page, pluginSettingsToken);
    await page.goto(SETTINGS_PATH);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  });

  // @scenario mode=test,live_credentials=complete
  // @effects test_mode_saves_without_live_credentials
  test('설정 화면이 열리고 다국어 원문 키가 노출되지 않는다', async ({ page }) => {
    await expect(page.getByText('NHN KCP').first()).toBeVisible({ timeout: 20_000 });

    // 번역되지 않은 원문 키가 화면에 남아 있으면 안 된다.
    const raw = await page.getByText('$t:').count();
    expect(raw).toBe(0);
  });

  // @scenario mode=live,live_credentials=missing_site_cd
  // @effects live_mode_requires_site_cd_and_enc_key
  test('운영 모드로 전환하면 경고 배너가 나타난다', async ({ page }) => {
    const toggle = page.locator('[name="is_test_mode"]').first();
    await expect(toggle).toBeVisible({ timeout: 20_000 });

    await toggle.click();

    await expect(page.getByText(/운영 모드|라이브/).first()).toBeVisible({ timeout: 10_000 });
  });

  // @scenario mode=live,live_credentials=missing_site_cd
  // @effects live_mode_requires_site_cd_and_enc_key
  test('운영 자격증명을 비운 채 저장하면 저장이 막히고 안내가 표시된다', async ({ page }) => {
    const toggle = page.locator('[name="is_test_mode"]').first();
    await toggle.click();

    const saveButton = page.getByRole('button', { name: /저장/ }).last();
    await expect(saveButton).toBeEnabled({ timeout: 10_000 });

    const [response] = await Promise.all([
      page.waitForResponse(
        (res) => res.url().includes('/settings') && res.request().method() === 'PUT',
        { timeout: 20_000 },
      ),
      saveButton.click(),
    ]);

    expect(response.status()).toBe(422);
    await expect(page.getByText(/사이트코드|암호화 키/).first()).toBeVisible({ timeout: 10_000 });
  });
});
