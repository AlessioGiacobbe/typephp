#!/usr/bin/python
# -*- coding: UTF-8 -*-
from selenium import webdriver
from selenium.webdriver.edge.options import Options
from selenium.webdriver.common.by import By
from selenium.common.exceptions import NoSuchElementException,InvalidArgumentException
from selenium.webdriver import ActionChains
from selenium.webdriver.common.actions.action_builder import ActionBuilder
import json,base64,time,random,hashlib,math,operator,sys,os,sqlite3,_thread,atexit
from PIL import Image
from functools import reduce

# 程序初始化
global randarr,game,driver,file_username,sqlite3_cur,isCreateImage,isCityWar,isLog,runUsername
isLog = False
isCityWar = False
isCreateImage = False
isGarrisonQueueRand = True
randarr = {}

# ----------------程序功能函数库---------------
def mkdirs(dirs):
    if not os.path.exists(dirs):
        os.makedirs(dirs)

def ocr(path):
    pass
    # ocr = PaddleOCR(enable_mkldnn=True,use_angle_cls=False,use_gpu=False, lang="ch")
    # result = ocr.ocr(path, cls=False)
    # return result
def connetctDb():
    global sqlite3_cur
    sqlite3_conn = sqlite3.connect('data/ttapp.db')
    print ("sqlite3数据库连接成功")
    sqlite3_cur = sqlite3_conn.cursor()
def initDb():
    global sqlite3_cur,isLog
    if isLog:
        connetctDb()
        try:
            create_tb_cmd='''
            CREATE TABLE IF NOT EXISTS game_resource_grain_data_log
            (id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid TEXT    NOT NULL,
            estimated_quantity  INTEGER     NOT NULL,
            quantity INTEGER     NOT NULL,
            create_time  INTEGER     NOT NULL
            );
            '''
            sqlite3_cur.execute(create_tb_cmd)
            create_tb_cmd='''
            CREATE TABLE IF NOT EXISTS game_resource_timber_data_log
            (id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid TEXT    NOT NULL,
            estimated_quantity  INTEGER     NOT NULL,
            quantity INTEGER     NOT NULL,
            create_time  INTEGER     NOT NULL
            );
            '''
            sqlite3_cur.execute(create_tb_cmd)
            create_tb_cmd='''
            CREATE TABLE IF NOT EXISTS game_resource_stone_data_log
            (id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid TEXT    NOT NULL,
            estimated_quantity  INTEGER     NOT NULL,
            quantity INTEGER     NOT NULL,
            create_time  INTEGER     NOT NULL
            );
            '''
            sqlite3_cur.execute(create_tb_cmd)
            create_tb_cmd='''
            CREATE TABLE IF NOT EXISTS game_resource_iron_data_log
            (id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid TEXT    NOT NULL,
            estimated_quantity INTEGER   NOT NULL,
            quantity INTEGER     NOT NULL,
            create_time INTEGER    NOT NULL
            );
            '''
            #主要就是上面的语句
            sqlite3_cur.execute(create_tb_cmd)
        except Exception as err:
            print("Create table failed")
            print(err)
            return False
def init():
    global driver,runUsername
    mkdirs('data')
    mkdirs('runtime/temp')
    initDb()
    options = Options()
    # prefs = {'download.default_directory' : '/dev/null',
    #          'download.prompt_for_download': False,
    #          'download.directory_upgrade': True,
    #          'safebrowsing.enabled': True}
    # options.add_experimental_option('prefs', prefs)
    options.add_experimental_option("excludeSwitches",["enable-automation"])
    options.add_experimental_option("useAutomationExtension",False)
    options.add_argument("--allow-reporter-logs=false")
    options.add_argument('--disable-dev-shm-usage')
    options.add_argument('--disable-blink-features=AutomationControlled')
    if sys.platform == 'linux':
        # options.add_argument("--disable-gpu")
        # options.add_argument('--no-sandbox')
        # options.add_argument('--user-data-dir=./'+file_username)
        pass
    driver = webdriver.Edge(options=options)
    driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {
    "source": """
        Object.defineProperty(navigator, 'webdriver', {
        get: () => undefined
        })
    """
    })
    # driver.set_window_size(700, 950)
    driver.set_window_size(700, 950)
    driver.implicitly_wait(30)
    driver.get("https://www.huya.com/myfollow")
    with open(file_username+'.cookies.json', 'r') as cookies_file:
        cookies_data = json.load(cookies_file)
    for cookie in cookies_data:
        driver.add_cookie(cookie)
    
    # runUsername = driver.get_cookie('username')['value']
    runUsername = ''
    
# 流程第一步刷新页面
def refresh(time = 15,initOneLoad = False):
    driver.get('https://www.huya.com/16695719')
    if initOneLoad:
        driver.find_element(By.ID,'player-gift-word').click()
    find_element_game()
    wait(time,'程序加载中。。。')

# 找到Canvas
def find_element_game():
    driver.find_element(By.CSS_SELECTOR,'.more-activity-icon').click()
    driver.find_element(By.ID,'front-0cenz7bj_web_video_com').click()
    app = driver.find_element(By.CSS_SELECTOR,'.videoComp-90808de0[style="width: 566px; height: 788px; top: 50%; left: 50%;"]')
    driver.switch_to.frame(app.find_element(By.CSS_SELECTOR,'iframe'))
    driver.switch_to.frame(driver.find_element(By.CSS_SELECTOR,'body > iframe'))
    global game
    game = driver.find_element(By.ID,'GameCanvas')

# 等待游戏加载
def wait(n,msg=''):
    for i in range(0,n):
        time.sleep(1)
        print (msg+str(i+1)+'s')

# take_element_screenshot
def take_element_screenshot(path=''):
    name = ''
    if path != '':
        name = str(random.randint(11,999999999))
    runtimename = 'runtime'+os.sep+path+'Screenshot'+name+'.'+file_username+'.png';
    screenshotjs = '''
    let callback = arguments[arguments.length - 1];
    cc.director.on(cc.Director.EVENT_AFTER_DRAW, () => {
        // 获取画布元素
        let gameCanvas = document.getElementById("GameCanvas")
        // 图片转换为(base64)dataURL
        let imagebase64 = gameCanvas.toDataURL() 
        // 取消渲染注册
        cc.director.off(cc.Director.EVENT_AFTER_DRAW)
        callback(imagebase64)
    })
    '''
    screenshot = driver.execute_async_script(screenshotjs)
    screenshot = screenshot.replace('data:image/png;base64,','')
    screenshot = base64.urlsafe_b64decode(screenshot)
    with open(runtimename, "wb") as screenshot_file:
      screenshot_file.write(screenshot)
    return runtimename
def load_config():
    with open(file_username+'.config.json', 'r') as config_file:
        config = json.load(config_file)
    return config
def random_game_type(config):
    global randarr
    sha256 = hashlib.sha256()
    sha256.update(str(config).encode('utf-8'))
    key = sha256.hexdigest()
    print(key)
    max = 0
    if not key in randarr:
        randarr.update({key:[]})
        print(config.values())
        for temp in config.values():
            max += float(temp)
        for k,v in config.items():
            v = float(v)/max*10000

            for val in range(0,int(v)):
                # print(k)
                randarr[key].append(k)
            
    return randarr[key][random.randint(0,len(randarr[key]))]
def actions_game_click(x,y,time = 1):
    global driver,game
    w = 283
    h = 394
    if x == w:
        x = 0        
    else:
        x = x-w
    if y == h:
        y = 0
    else:
        y = y-h

    # action = ActionBuilder(driver)
    # action.pointer_action.move_to_location(x,y)\
    #     .click()
    # action.perform()
    ActionChains(driver)\
    .move_to_element_with_offset(game, x, y)\
    .click()\
    .perform()
    wait(time,'操作等待。。')
def page_drag(x,y,x1,y1):
    # 鼠标拖动页面
    action_builder = ActionBuilder(driver)
    action_builder.pointer_action.move_to_location(x,y).click_and_hold()
    action_builder.pointer_action.move_to_location(x1,y1)  
    action_builder.key_action.pause()
    action_builder.pointer_action.release()
    action_builder.key_action.pause()
    action_builder.perform()
def image_compare(image1,image2):
    '''
    :param pic1: 图片1路径
    :param pic2: 图片2路径
    :return: 返回对比的结果
    '''
    histogram1 = image1.histogram()
    histogram2 = image2.histogram()

    differ = math.sqrt(reduce(operator.add, list(map(lambda a,b: (a-b)**2,histogram1, histogram2)))/len(histogram1))
    print('图片相识度',differ)
    return differ < 15.0

def image_crop(path,w,h,x1,y1):
    '''
    :param path: 图片1路径
    :param w: 宽度
    :param h: 高度
    :param x1: x坐标
    :param y1: y坐标
    :return: 返回对比的结果
    '''
    im = Image.open(path)
    #crop（x1，y1，x2，y2） 裁剪的是矩形  左上（x1，y1）  到右下（x2，y2）
    cropim = im.crop((x1, y1, x1+w, y1+h)) 
    # cropim.save("cropim.png")
    global isCreateImage
    if isCreateImage:
        cropim.save(path)
    return cropim

def check_equal(runtimename,name,w,h,x1,y1):
    cropim = image_crop(runtimename,w,h,x1,y1)
    name = 'static'+ os.sep + name
    im = Image.open(name)
    return image_compare(im,cropim)
def ocr_list(cropim):
    runtimename = 'runtime/ScreenshotCropOcr.'+file_username+'.png'
    cropim.save(runtimename)
    result = ocr(runtimename)
    temp = []
    for line in result:
        temp.append(line[1][0])
    return temp
def check_equal_ocr(runtimename,w,h,x1,y1):
    cropim = image_crop(runtimename,w,h,x1,y1)
    return ocr_list(cropim)

# --------------------------业务动作函数库--------------------------
yyy = 45 # 不同的显示偏移
def home_resource_points():
    actions_game_click(35,597+25+yyy)
    print('当前函数：',sys._getframe().f_code.co_name)

def resource_points_ok():
    actions_game_click(267-67,543+25+yyy)
    print('当前函数：',sys._getframe().f_code.co_name)

def resource_points_my():
    actions_game_click(106-67,189+25+yyy)
    print('当前函数：',sys._getframe().f_code.co_name)

def resource_points_snatch():
    actions_game_click(117-67,264+25+yyy)
    print('当前函数：',sys._getframe().f_code.co_name)

def resource_points_farmland_garrison(t=3):
    actions_game_click(500-67,146+25+yyy,t)
    print('当前函数：',sys._getframe().f_code.co_name)

def resource_points_logging_yard_garrison(t=3):
    actions_game_click(500-67,193+25+yyy,t)
    print('当前函数：',sys._getframe().f_code.co_name)

def resource_points_quarry_garrison(t=3):
    actions_game_click(500-67,245+25+yyy,t)
    print('当前函数：',sys._getframe().f_code.co_name)

def resource_points_iron_ore_stationed(t=3):
    actions_game_click(500-67,291+25+yyy,t)
    print('当前函数：',sys._getframe().f_code.co_name)

def go_resource_points():
    actions_game_click(100-67,95+25+yyy)
    print('当前函数：',sys._getframe().f_code.co_name)

def resource_points_details_close():
    actions_game_click(555-67,35+25+yyy)
    print('当前函数：',sys._getframe().f_code.co_name)

def resource_points_details_garrison():
    actions_game_click(345-67,571+25+yyy)
    print('当前函数：',sys._getframe().f_code.co_name)

def resource_points_details_obtain_ok():
    actions_game_click(345-67,525+25+yyy)
    print('当前函数：',sys._getframe().f_code.co_name)
def resource_points_next_page():
    # 资源列表下一页
    page_drag(343-67,564+25+yyy,343-67,564+25+yyy-260)
def resource_points_pre_page():
    # 资源列表上一页
    page_drag(343-67,330+25+yyy,343-67,330+25+yyy+260)
def resource_points_ok2():
    actions_game_click(488-67,543+25+yyy)
    print('当前函数：',sys._getframe().f_code.co_name)
def resource_points_ok3():
    # 起始位为默认位置 否则使用resource_points_ok
    resource_points_next_page()
    actions_game_click(267-67,543+25+yyy)
    print('当前函数：',sys._getframe().f_code.co_name)
# 首页王城入口
def city_home():
    actions_game_click(43,188,3)
    print('当前函数：',sys._getframe().f_code.co_name)
# 关闭王城说明
def city_close_say():
    actions_game_click(531,158,3)
    print('当前函数：',sys._getframe().f_code.co_name)
# 展开王城资源列表
def city_open_resource_list():
    actions_game_click(516,277)
    print('当前函数：',sys._getframe().f_code.co_name)
# 一键收取王城资源
def city_one_key_resource_list():
    actions_game_click(416,649)
    print('当前函数：',sys._getframe().f_code.co_name)
def city_go_home():
    actions_game_click(31,31)
    print('当前函数：',sys._getframe().f_code.co_name)
# --------------------------业务函数库-----------------------------
def ok_over():
    resource_points_snatch()
    resource_points_my()

def check_garrison():
    runtimename = take_element_screenshot()
    result = check_equal(runtimename,'noGarrisonCompare.png',360, 32,103,304)
    # os.remove(runtimename)
    print('当前函数：',sys._getframe().f_code.co_name)
    return result
def check_garrison_idle_barracks():
    runtimename = take_element_screenshot()
    result = check_equal(runtimename,'noIdleBarracksCompare.png',50,25,420,180)
    # os.remove(runtimename)
    print('当前函数：',sys._getframe().f_code.co_name)
    return result

def check_garrison_has_idle_barracks():
    runtimename = take_element_screenshot()
    result = check_equal(runtimename,'IdleBarracksCompare.png',50,25,420,180)
    # os.remove(runtimename)
    print('当前函数：',sys._getframe().f_code.co_name)
    return result
def check_garrison_has_or_no_idle_barracks():
    runtimename = take_element_screenshot()
    result = False
    if check_equal(runtimename,'noIdleBarracksCompare.png',50,25,420,180):
        result = 1
    elif check_equal(runtimename,'IdleBarracksCompare.png',50,25,420,180):
        result = 2
    # os.remove(runtimename)
    print('当前函数：',sys._getframe().f_code.co_name)
    return result
def check_resource_points_not_stationed():
    runtimename = take_element_screenshot()
    result = check_equal(runtimename,'ResourcePointsAreNotStationedAtThisTimeCompare.png',115, 65,110,410)
    # os.remove(runtimename)
    print('当前函数：',sys._getframe().f_code.co_name)
    return result

def check_resource_points_cancel():
    runtimename = take_element_screenshot()
    result = check_equal(runtimename,'CancelGarrisonCompare.png',73, 20,198,599)
    # os.remove(runtimename)
    print('当前函数：',sys._getframe().f_code.co_name)
    return result
def check_resource_points_ok():
    runtimename = take_element_screenshot()
    result = check_equal(runtimename,'okGarrisonCompare.png',60, 20,157,599)
    # os.remove(runtimename)
    print('当前函数：',sys._getframe().f_code.co_name)
    return result
def check_resource_points_ok2():
    runtimename = take_element_screenshot()
    result = check_equal(runtimename,'okGarrisonCompare2.png',73, 20,198,599)
    # os.remove(runtimename)
    print('当前函数：',sys._getframe().f_code.co_name)
    return result
def check_resource_points_has_ok():
    runtimename = take_element_screenshot()
    if check_equal(runtimename,'okGarrisonCompare.png',60, 20,157,599):
        result = 1
    elif check_equal(runtimename,'okGarrisonCompare2.png',73, 20,198,599):
        result = 2
    elif check_equal(runtimename,'CancelGarrisonCompare.png',73, 20,198,599):
        result = 3
    else:
        result = False
    # os.remove(runtimename)
    print('当前函数：',sys._getframe().f_code.co_name)
    return result
def check_resource_points_details_cancel():
    runtimename = take_element_screenshot()
    result = check_equal(runtimename,'CancelGarrisonCompare2.png',70, 28,196,624)
    # os.remove(runtimename)
    print('当前函数：',sys._getframe().f_code.co_name)
    return result
def check_resource_points_page():
    runtimename = take_element_screenshot()
    result = check_equal(runtimename,'resourcePointsPage.png',238,60,20,205)
    # os.remove(runtimename)
    print('当前函数：',sys._getframe().f_code.co_name)
    return result

def over_garrison_xy(xy=0):
    if xy == 0:
        resource_points_ok()
    elif xy==1:
        resource_points_ok2()
    elif xy==2:
        resource_points_ok3()
    else:
        pass
# 取消驻守
def over_garrison(n = 1,xy= 0):
    i = 0
    while i < n:
        print('取消驻守循环：',i,'-',n)
        if not check_resource_points_page():
            refresh()
            home_resource_points()
        if check_resource_points_not_stationed():
            # 是否有资源驻守
            print('未有驻守的资源点1')
            return False
        result = check_resource_points_has_ok()
        print('xxxxxxx',result,'xxxxxxx')
        if result == 1:
            over_garrison_xy(xy)
            if check_resource_points_ok():
                continue
        elif result == 2:
            over_garrison_xy(xy)
            if check_resource_points_ok2():
                continue
        elif result == 3:
            over_garrison_xy(xy)
            resource_points_details_garrison()
            if check_resource_points_details_cancel():
                resource_points_details_close()
                continue
        else:
            if check_resource_points_not_stationed():
                print('未有驻守的资源点2')
                return False
            if check_resource_points_details_cancel():
                resource_points_details_close()
            continue
        resource_points_details_obtain_ok()
        i += 1
# 驻守
def run_garrison(n = 1,types = ''):
    i = 0
    step = 0
    sleep = 3
    if isGarrisonQueueRand == False:
        type = random_game_type(types)
        print('本轮驻守类型'+type)
    while i < n:
        if isGarrisonQueueRand:
            type = random_game_type(types)
            print('本轮该兵营驻守类型'+type)
        type = int(type)
        if type == 1:
            resource_points_farmland_garrison(sleep)
        elif type == 2:
            resource_points_logging_yard_garrison(sleep)
        elif type == 3:
            resource_points_quarry_garrison(sleep)
        elif type == 4:
            resource_points_iron_ore_stationed(sleep)
        else:
            print('资源类型判断失败')
        if check_resource_points_page():
            print('------0----信息更新-----')
            continue

        if not check_garrison():
            # 有人占了 没有到资源点详情
            print('------1----check_garrison-----')
            step = step + 1
            # 临时处理 页面空白情况 等待延迟到6秒
            sleep = sleep * 2
            if sleep > 6:
                sleep = 6
            if step > 10:
                take_element_screenshot('temp'+os.sep)
                refresh()
                home_resource_points()
                step = 0
                continue
            resource_points_details_close()
            go_resource_points()
            continue
        resource_points_details_garrison()
        if check_garrison():
            # true 表示 卡住了 有人占了
            print('------2----check_garrison-----')
            resource_points_details_close()
            go_resource_points()
            continue
        resource_points_details_garrison()
        res = check_garrison_has_or_no_idle_barracks()
        if res == 1:
            # 驻守中 
            resource_points_details_close()
        elif res == 2:
            # 空闲 有人驻守了 跳出循环重新选资源
            resource_points_details_close()
            go_resource_points()
            continue
        else:
            pass
        go_resource_points()
        if check_resource_points_page():
            print('-------go home----------')
        else:
            print('-------go home fail reload go home ----------')
            go_resource_points()

        sleep = 3
        i = i+1

#一键 王城
def resource_one_key_king_over():
    # 关掉资源页
    resource_points_details_close()
    # 进入王城争夺
    city_home()
    # 关闭说明
    city_close_say()
    # 展开占领资源
    city_open_resource_list()
    # 一键领取
    city_one_key_resource_list()
    resource_points_details_obtain_ok()
    city_go_home()
    home_resource_points()
    pass

def resource_points_xy_screenshot_ocr(filename,xy=0):
    if xy == 0:
        cropim = image_crop(filename,180,230,100,400)
    else:
        cropim = image_crop(filename,337,30,120,140)
    return ocr_list(cropim)
def check_resource_points_over(n=1,t=1,times=0):
    i = 0
    temp = n
    # 更新游戏截图
    filename = take_element_screenshot()
    while i < n:
        if i < 2:
            result = resource_points_xy_screenshot_ocr(filename,i)
            if '收取' in result:
                over_garrison(n=1,xy=i)
                filename = take_element_screenshot()
                n-=1
            else:
                i+=1
        else:
            resource_points_next_page()
            filename = take_element_screenshot()
            result = resource_points_xy_screenshot_ocr(filename,0)
            if '收取' in result:
                over_garrison(n=1,xy=0)
                n-=1
            else:
                i+=1
            ok_over()
    # 根据 n 剩余等待时间 开始补充驻守
    if temp-n > 0 and times > 60*3:
        run_garrison(temp-n,t)        

def check_over_resource_points_thread(msg,n=1,type=1,times= 0):
    print('OCR截图检测线程',n,type,msg)
    check_resource_points_over(n,type,times)
    print('OCR截图检测线程结束')
    
# -----------------------主程序---------------------------
def run_image(name='run'):
    global file_username,isCityWar,driver,game,isCreateImage
    file_username = name
    isCreateImage = True
    init()
    refresh(5)
    home_resource_points()
    ok_over()
    # ----------------------------
    # check_resource_points_page()
    # check_resource_points_not_stationed()
    # check_resource_points_ok()
    # check_resource_points_ok2()
    # ----------------------------
    resource_points_farmland_garrison()
    # -----------------------------
    check_garrison()
    # -----------------------------
    # resource_points_details_garrison()
    # ----------------------------
    # check_garrison_has_idle_barracks()
    # check_garrison_idle_barracks()
    # ----------------------------
    # resource_points_details_close()
    # go_resource_points()
    # ----------------------------
    # check_resource_points_cancel()
    # ----------------------------
    # resource_points_ok()
    # ------------------------
    # check_resource_points_details_cancel()
    # ------------------------
    # resource_points_details_garrison()
    # resource_points_details_obtain_ok()
    pass

def run(numberOfBarracks = 1,type = '',times = 600):
    global isCityWar,isLog,file_username
    if not check_resource_points_page():
        refresh()
        home_resource_points()
    ok_over()
    over_garrison(numberOfBarracks)
    run_garrison(numberOfBarracks,type)
    if isLog:
        over_nums_log()
    if isCityWar:
        resource_one_key_king_over()
    
    #  休眠开始写入时间
    timeFileName = file_username + '.runtime'
    startSleepTime = int(round(time.time() * 1000))
    endSleepTime = startSleepTime + (times*1000)
    timeFile = str(startSleepTime) + ',' + str(endSleepTime)
    with open(timeFileName, "wb") as timeFile_file:
      timeFile_file.write(timeFile.encode('utf-8'))
    # 更新 cookies
    current_time = time.time() # 获取当前时间
    one_day = 86400  # 定义时间间隔为一天（86400秒）
    # 检查是否需要重新获取cookies
    if 'last_cookie_time' not in driver.__dict__ or current_time - driver.last_cookie_time >= one_day:
        # 更新cookies
        cookies = driver.get_cookies()
        if cookies:
            cookies = json.dumps(cookies)
            with open(file_username + '.cookies.json', 'wb') as cookies_file:
                cookies_file.write(cookies.encode('utf-8'))
        
        # 更新最后获取cookie的时间
        driver.last_cookie_time = current_time
    #  休眠
    for i in range(0,times):
        # 取10 并且不等于0 
        # if i > 0 and not i%60:
            # 截图进行检测
            # print('开启线程截图检测')
            # _thread.start_new_thread(check_over_resource_points_thread,('thread-check_over_resource_points-1',numberOfBarracks,type,i,))
        time.sleep(1)
        print (file_username+'程序休眠中。。。'+str(i+1)+'s')
    # 休眠结束写入时间
    timeFile = '';
    with open(timeFileName, "wb") as timeFile_file:
      timeFile_file.write(timeFile.encode('utf-8'))
def over_nums_log():
    global runUsername
    sqlite3_conn = sqlite3.connect('data/ttapp.db')
    print ("sqlite3数据库连接成功")
    sqlite3_cur = sqlite3_conn.cursor()
    runtimename = take_element_screenshot()
    cropim = image_crop(runtimename,566,88,0,700)
    runtimename = 'runtime/ScreenshotCropOcr.'+file_username+'.png'
    cropim.save(runtimename)
    reslut = ocr(runtimename)
    # print (reslut)
    temp = []
    for line in reslut:
        if not line[1][0] == '+' and not line[1][0] == '旅':
            temp.append(line[1][0])
    print (temp)
    t = time.time()
    t = int(t)
    try:
        sql='''INSERT INTO game_resource_grain_data_log (uid,estimated_quantity,quantity,create_time) VALUES ('%s',0,%s,%s);'''
        sql = sql % (runUsername,temp[0],t)
        print(sql)
        sqlite3_cur.execute(sql)
        sql='''INSERT INTO game_resource_timber_data_log (uid,estimated_quantity,quantity,create_time) VALUES ('%s',0,%s,%s);'''
        sql = sql % (runUsername,temp[1],t)
        print(sql)
        sqlite3_cur.execute(sql)
        sql='''INSERT INTO game_resource_stone_data_log (`uid`,`estimated_quantity`,`quantity`,`create_time`) VALUES ('%s',0,%s,%s);'''
        sql = sql % (runUsername,temp[2],t)
        print(sql)
        sqlite3_cur.execute(sql)
        sql = '''INSERT INTO game_resource_iron_data_log (`uid`,`estimated_quantity`,`quantity`,`create_time`) VALUES ('%s',0,%s,%s);'''
        sql = sql % (runUsername,temp[3],t)
        print(sql)
        sqlite3_cur.execute(sql)
        sqlite3_conn.commit()
    except Exception as e:
        print('资源数据记录',e)
def run_game_shell(name = 'run'):
    global file_username,isCityWar
    file_username = name
    init()
    initOneLoad = True
    if sys.platform == 'linux':
        initOneLoad = False
    refresh(15,initOneLoad)
    home_resource_points()
    while True:
        # 每次循环 重新加载配置
        config = load_config()
        # 
        isCityWar = config['cityWar']['isOpen']
        type = config['arr']
        if 'isGarrisonQueueRand' in config:
            isGarrisonQueueRand = config['isGarrisonQueueRand']
        try:
            run(config['numberOfBarracks'],type,config['time'])
        except Exception as err:
            print(err)
            refresh()
            wait(30)
            home_resource_points()
            continue;
def login(name='run'):
    options = Options()
    global driver
    driver = webdriver.Edge(options=options)
    driver.execute_cdp_cmd("Page.addScriptToEvaluateOnNewDocument", {
    "source": """
        Object.defineProperty(navigator, 'webdriver', {
        get: () => undefined
        })
    """
    })
    driver.implicitly_wait(10)
    driver.get('https://www.huya.com/myfollow')
    wait(100,'请在倒计时结束前完成登录。。。')
    cookies = driver.get_cookies()
    if not cookies == []:
        cookies = json.dumps(cookies)
        with open(name+'.cookies.json', 'wb') as cookies_file:
            cookies_file.write(cookies.encode('utf-8'))
        configPath = name+'.config.json'
        if not os.path.exists(configPath):
            configtext = '''{"arr":{"1":"0.1","2":"0.1","3":"0.1","4":"0.1"},"numberOfBarracks":3,"time":840,"cityWar":{"isOpen":false}}'''
            with open(configPath, 'wb') as cookies_file:
                cookies_file.write(configtext.encode('utf-8'))

    driver.quit()

@atexit.register
def cleanExit():
    timeFile = sys.argv[1:][0] + '.runtime'
    if os.path.exists(timeFile):
        os.unlink(timeFile)

def main(argv):
    print(argv)
    if not argv == [] and argv[0] == 'login':
        print('login ...')
        if len(argv) > 1:
            login(argv[1])
        else:
             login()
        print('login over')
    elif not argv == [] and argv[0] == 'image':
        run_image(argv[1])
        pass
    else:
        print('rungame ...')
        if len(argv) == 1:
            run_game_shell(argv[0])
        elif len(argv) == 2:
            global isCreateImage
            isCreateImage = True
            run_game_shell(argv[0])
        else:
            print('not give argv')
main(sys.argv[1:])
